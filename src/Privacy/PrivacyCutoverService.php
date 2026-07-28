<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Http\HttpException;
use PDO;
use Throwable;

final class PrivacyCutoverService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly FinancialPrivacyStateService $states,
        private readonly FinancialRevisionService $revisions,
        private readonly PrivacyMigrationRepository $migrations,
        private readonly MigrationStagingRepository $staging,
        private readonly VaultRepository $vaults,
        private readonly PrivacyCleanupRepository $cleanup
    ) {
    }

    /** @return array<string,mixed> */
    public function cutover(int $userId, string $migrationId): array
    {
        $existing = $this->migrations->getByPublicId($userId, $migrationId);
        if ($existing === null) throw new HttpException(404, 'MIGRATION_NOT_FOUND', 'Migration run not found');
        if ((string) $existing['status'] === 'completed' && $this->states->get($userId) === FinancialPrivacyState::ENCRYPTED) {
            return $this->result($userId, $migrationId, true);
        }
        if ((string) $existing['status'] !== 'active') throw new HttpException(409, 'MIGRATION_STATE_INVALID', 'Migration is not active');
        if ($this->vaults->findByUser($userId) === null) throw new HttpException(409, 'MIGRATION_VAULT_REQUIRED', 'Initialize the financial Vault before cutover');

        $this->pdo->beginTransaction();
        try {
            $user = $this->lockedRow('SELECT financial_privacy_state, financial_revision FROM users WHERE id=:user_id FOR UPDATE', [':user_id' => $userId]);
            if ($user === null) throw new HttpException(404, 'USER_NOT_FOUND', 'User not found');
            if ((string) $user['financial_privacy_state'] !== FinancialPrivacyState::MIGRATION_IN_PROGRESS->value) throw new HttpException(409, 'PRIVACY_STATE_CONFLICT', 'Cutover requires an active migration state');

            $migration = $this->lockedRow('SELECT migration_id,status,source_financial_revision FROM financial_privacy_migrations WHERE user_id=:user_id AND migration_id=:migration_id FOR UPDATE', [':user_id'=>$userId, ':migration_id'=>$migrationId]);
            if ($migration === null) throw new HttpException(404, 'MIGRATION_NOT_FOUND', 'Migration run not found');
            if ((string) $migration['status'] !== 'active') throw new HttpException(409, 'MIGRATION_STATE_INVALID', 'Migration is not active');
            if ((int) $user['financial_revision'] !== (int) $migration['source_financial_revision']) throw new HttpException(409, 'MIGRATION_SOURCE_REVISION_CHANGED', 'The migration source revision changed');

            $status = $this->staging->counts($migrationId, $userId);
            if ($status['expected_count'] === null || $status['expected_count'] !== $status['staged_count'] || !$status['verified'] || !$status['finalized']) {
                throw new HttpException(409, 'MIGRATION_NOT_VERIFIED', 'Encrypted staging is not fully verified');
            }
            $manifest = $this->staging->manifest($migrationId, $userId);
            $expected = [];
            foreach (($manifest['targets'] ?? []) as $target) {
                if (!is_array($target) || !isset($target['target_record_id'], $target['record_family'], $target['record_schema_version'])) throw new HttpException(409, 'MIGRATION_MANIFEST_MISMATCH', 'Encrypted staging manifest is invalid');
                $expected[(string)$target['target_record_id']] = ['record_family'=>(string)$target['record_family'], 'record_schema_version'=>(string)$target['record_schema_version']];
            }
            if ($expected !== $this->staging->targetMetadata($migrationId, $userId)) throw new HttpException(409, 'MIGRATION_MANIFEST_MISMATCH', 'Encrypted staging does not match the target manifest');

            $vault = $this->vaults->findByUser($userId);
            if (!is_array($vault) || (int)$vault['crypto_profile_version'] !== 1) throw new HttpException(409, 'MIGRATION_VAULT_UNSUPPORTED', 'The financial Vault profile is unsupported');
            $this->pdo->prepare('INSERT IGNORE INTO encrypted_record_sync_state (user_id,next_sync_sequence) VALUES (:user_id,0)')->execute([':user_id'=>$userId]);
            $sequence = 0;
            $rows = $this->pdo->prepare('SELECT target_record_id,record_family,record_schema_version,envelope_version,iv,ciphertext FROM encrypted_migration_records WHERE user_id=:user_id AND migration_id=:migration_id ORDER BY target_record_id ASC FOR UPDATE');
            $rows->execute([':user_id'=>$userId, ':migration_id'=>$migrationId]);
            foreach ($rows->fetchAll() as $row) {
                $recordId = (string)$row['target_record_id'];
                if (strlen($recordId) > 96) throw new HttpException(409, 'MIGRATION_RECORD_INVALID', 'A promoted record identity is too long');
                $sequence++;
                $mutationId = 'migration:' . $migrationId . ':' . $recordId;
                $digest = hash('sha256', $mutationId . '|' . (string)$row['record_family'] . '|' . (string)$row['record_schema_version'] . '|' . hash('sha256', (string)$row['ciphertext']));
                $data = [':user_id'=>$userId, ':vault_id'=>$vault['vault_id'], ':record_id'=>$recordId, ':version'=>(int)$row['envelope_version'], ':revision'=>1, ':iv'=>$row['iv'], ':ciphertext'=>$row['ciphertext'], ':sequence'=>$sequence, ':mutation_id'=>$mutationId, ':digest'=>$digest];
                $this->pdo->prepare('INSERT INTO encrypted_financial_records (user_id,vault_id,record_id,envelope_version,record_revision,iv,ciphertext,sync_sequence,is_deleted,last_mutation_id,last_mutation_digest) VALUES (:user_id,:vault_id,:record_id,:version,:revision,:iv,:ciphertext,:sequence,0,:mutation_id,:digest)')->execute($data);
                $this->pdo->prepare('INSERT INTO encrypted_record_changes (user_id,vault_id,record_id,envelope_version,record_revision,iv,ciphertext,sync_sequence,is_deleted) VALUES (:user_id,:vault_id,:record_id,:version,:revision,:iv,:ciphertext,:sequence,0)')->execute([':user_id'=>$userId, ':vault_id'=>$vault['vault_id'], ':record_id'=>$recordId, ':version'=>(int)$row['envelope_version'], ':revision'=>1, ':iv'=>$row['iv'], ':ciphertext'=>$row['ciphertext'], ':sequence'=>$sequence]);
            }
            $this->pdo->prepare('UPDATE encrypted_record_sync_state SET next_sync_sequence=:sequence WHERE user_id=:user_id')->execute([':sequence'=>$sequence, ':user_id'=>$userId]);
            $this->pdo->prepare("UPDATE financial_privacy_migrations SET status='completed', encrypted_schema_version='phase3_envelope_v1', completed_at=UTC_TIMESTAMP() WHERE user_id=:user_id AND migration_id=:migration_id AND status='active'")->execute([':user_id'=>$userId, ':migration_id'=>$migrationId]);
            $this->states->transitionInTransaction($userId, FinancialPrivacyState::ENCRYPTED);
            $this->staging->deleteForMigration($migrationId, $userId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        try {
            if ($this->cleanup->getForMigration($userId, $migrationId) === null) $this->cleanup->createPending($userId, $migrationId);
        } catch (Throwable) {
            // Authority is already committed. Status remains encrypted and a later operator retry can schedule cleanup.
        }
        return $this->result($userId, $migrationId, false);
    }

    /** @return array<string,mixed> */
    private function result(int $userId, string $migrationId, bool $idempotent): array
    {
        $job = $this->cleanup->getForMigration($userId, $migrationId);
        return ['financial_privacy_state'=>FinancialPrivacyState::ENCRYPTED->value, 'migration_id'=>$migrationId, 'idempotent'=>$idempotent, 'cleanup_status'=>$job === null ? null : ['cleanup_job_id'=>$job['cleanup_job_id'], 'status'=>$job['status']]];
    }

    /** @return array<string,mixed>|null */
    private function lockedRow(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }
}
