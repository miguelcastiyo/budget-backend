<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Auth\AuthContext;
use App\Http\HttpException;
use App\Http\Request;
use App\Security\AuditLogger;
use PDO;
use PDOException;

final class EncryptedRecordService
{
    public const MAX_CIPHERTEXT_BYTES = 262144;
    public const MAX_SYNC_LIMIT = 100;

    public function __construct(
        private readonly PDO $pdo,
        private readonly EncryptedRecordRepository $records,
        private readonly VaultRepository $vaults,
        private readonly FinancialPrivacyStateService $states,
        private readonly AuditLogger $audit
    ) {
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(AuthContext $auth, array $payload, ?Request $request = null): array
    {
        $userId = $this->guard($auth);
        $envelope = $this->validateEnvelope($payload['envelope'] ?? null, $userId, 1);
        $mutationId = $this->mutationId($payload['idempotency_key'] ?? null);
        $digest = $this->digest($envelope, $mutationId);
        $this->pdo->beginTransaction();
        try {
            $this->records->ensureSyncState($userId);
            $existing = $this->records->findForUpdate($userId, $envelope['record_id']);
            if ($existing !== null) {
                if (!$this->sameMutation($existing, $mutationId, $digest) && (int) $existing['is_deleted'] === 1) {
                    throw new HttpException(409, 'ENCRYPTED_RECORD_TOMBSTONED', 'Encrypted record identity cannot be reused');
                }
                if ($this->sameMutation($existing, $mutationId, $digest)) {
                    $result = $this->output($existing) + ['idempotent' => true];
                    $this->pdo->commit();
                    return $result;
                }
                throw new HttpException(409, 'ENCRYPTED_RECORD_ALREADY_EXISTS', 'Encrypted record already exists');
            }
            $envelope['sync_sequence'] = $this->records->nextSyncSequence($userId);
            $envelope['mutation_id'] = $mutationId;
            $envelope['digest'] = $digest;
            $this->records->insert($userId, $envelope);
            $this->records->appendChange($userId, $envelope);
            $row = $this->records->findForUpdate($userId, $envelope['record_id']);
            $this->pdo->commit();
            if ($request !== null && $row !== null) $this->auditMutation($request, $auth, 'encrypted_record.created', $row);
            return $this->output($row ?? $envelope);
        } catch (HttpException|PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($e instanceof PDOException && ($e->errorInfo[0] ?? '') === '23000') throw new HttpException(409, 'ENCRYPTED_RECORD_ALREADY_EXISTS', 'Encrypted record already exists');
            throw $e;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function get(AuthContext $auth, string $recordId): array
    {
        $userId = $this->guard($auth);
        $this->validateRecordId($recordId);
        $row = $this->records->find($userId, $recordId);
        if ($row === null) throw new HttpException(404, 'ENCRYPTED_RECORD_NOT_FOUND', 'Encrypted record not found');
        return $this->output($row);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function update(AuthContext $auth, string $recordId, array $payload, ?Request $request = null): array
    {
        $userId = $this->guard($auth);
        $this->validateRecordId($recordId);
        $expected = $this->expectedRevision($payload['expected_revision'] ?? null);
        $envelope = $this->validateEnvelope($payload['envelope'] ?? null, $userId, $expected + 1, $recordId);
        $mutationId = $this->mutationId($payload['idempotency_key'] ?? null);
        $digest = $this->digest($envelope, $mutationId);
        $this->pdo->beginTransaction();
        try {
            $this->records->ensureSyncState($userId);
            $existing = $this->records->findForUpdate($userId, $recordId);
            if ($existing === null) throw new HttpException(404, 'ENCRYPTED_RECORD_NOT_FOUND', 'Encrypted record not found');
            if ($this->sameMutation($existing, $mutationId, $digest)) { $this->pdo->commit(); return $this->output($existing) + ['idempotent' => true]; }
            if ((int) $existing['is_deleted'] === 1) throw new HttpException(409, 'ENCRYPTED_RECORD_TOMBSTONED', 'Encrypted record is deleted');
            if ((int) $existing['record_revision'] !== $expected) throw new HttpException(409, 'ENCRYPTED_RECORD_REVISION_CONFLICT', 'Encrypted record revision conflict', [['field' => 'current_revision', 'message' => (string) $existing['record_revision']]]);
            $envelope['sync_sequence'] = $this->records->nextSyncSequence($userId);
            $envelope['mutation_id'] = $mutationId;
            $envelope['digest'] = $digest;
            $this->records->update($userId, $recordId, $envelope);
            $this->records->appendChange($userId, $envelope);
            $row = $this->records->findForUpdate($userId, $recordId);
            $this->pdo->commit();
            if ($request !== null && $row !== null) $this->auditMutation($request, $auth, 'encrypted_record.updated', $row);
            return $this->output($row ?? $envelope);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function delete(AuthContext $auth, string $recordId, array $payload, ?Request $request = null): array
    {
        $userId = $this->guard($auth);
        $this->validateRecordId($recordId);
        $expected = $this->expectedRevision($payload['expected_revision'] ?? null);
        $mutationId = $this->mutationId($payload['idempotency_key'] ?? null);
        $digest = hash('sha256', $recordId . '|' . $expected . '|' . $mutationId);
        $this->pdo->beginTransaction();
        try {
            $this->records->ensureSyncState($userId);
            $existing = $this->records->findForUpdate($userId, $recordId);
            if ($existing === null) throw new HttpException(404, 'ENCRYPTED_RECORD_NOT_FOUND', 'Encrypted record not found');
            if ($this->sameMutation($existing, $mutationId, $digest)) { $this->pdo->commit(); return $this->output($existing) + ['idempotent' => true]; }
            if ((int) $existing['is_deleted'] === 1) throw new HttpException(409, 'ENCRYPTED_RECORD_TOMBSTONED', 'Encrypted record is deleted');
            if ((int) $existing['record_revision'] !== $expected) throw new HttpException(409, 'ENCRYPTED_RECORD_REVISION_CONFLICT', 'Encrypted record revision conflict');
            $data = ['vault_id' => (string) $existing['vault_id'], 'record_id' => $recordId, 'envelope_version' => (int) $existing['envelope_version'], 'record_revision' => $expected + 1, 'sync_sequence' => $this->records->nextSyncSequence($userId), 'mutation_id' => $mutationId, 'digest' => $digest, 'is_deleted' => 1, 'iv' => null, 'ciphertext' => null];
            $this->records->tombstone($userId, $recordId, $data);
            $this->records->appendChange($userId, $data);
            $row = $this->records->findForUpdate($userId, $recordId);
            $this->pdo->commit();
            if ($request !== null && $row !== null) $this->auditMutation($request, $auth, 'encrypted_record.deleted', $row);
            return $this->output($row ?? $data);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function sync(AuthContext $auth, mixed $after, mixed $limit): array
    {
        $userId = $this->guard($auth);
        $cursor = $this->cursor($after);
        $pageSize = $this->limit($limit);
        $this->records->ensureSyncState($userId);
        if ($cursor > $this->records->currentSyncSequence($userId)) throw new HttpException(422, 'ENCRYPTED_SYNC_CURSOR_INVALID', 'Sync cursor is invalid');
        $rows = $this->records->syncAfter($userId, $cursor, $pageSize + 1);
        $hasMore = count($rows) > $pageSize;
        if ($hasMore) array_pop($rows);
        $changes = array_map(fn(array $row): array => $this->output($row), $rows);
        $nextCursor = $rows === [] ? $cursor : (int) $rows[array_key_last($rows)]['sync_sequence'];
        return ['changes' => $changes, 'next_cursor' => (string) $nextCursor, 'has_more' => $hasMore];
    }

    /** Structural all-or-nothing mutation for domain commands. Financial meaning remains client-side. */
    public function batch(AuthContext $auth, array $payload, ?Request $request = null): array
    {
        $userId = $this->guard($auth);
        $batchKey = $this->mutationId($payload['idempotency_key'] ?? null);
        foreach (['creates', 'updates', 'tombstones'] as $key) if (!is_array($payload[$key] ?? null)) throw new HttpException(422, 'ENCRYPTED_RECORD_PAYLOAD_INVALID', 'Encrypted batch payload is structurally invalid');
        $this->pdo->beginTransaction();
        try {
            $this->records->ensureSyncState($userId);
            $marker = $this->pdo->prepare('SELECT result_json FROM encrypted_record_batches WHERE user_id=:user_id AND idempotency_key=:key FOR UPDATE');
            $marker->execute([':user_id'=>$userId, ':key'=>$batchKey]);
            $existingBatch = $marker->fetchColumn();
            if ($existingBatch !== false) { $this->pdo->commit(); return array_merge(json_decode((string)$existingBatch, true, 512, JSON_THROW_ON_ERROR), ['idempotent'=>true]); }
            $results = [];
            foreach ($payload['creates'] as $item) {
                if (!is_array($item)) throw new HttpException(422, 'ENCRYPTED_RECORD_PAYLOAD_INVALID', 'Encrypted batch payload is structurally invalid');
                $envelope = $this->validateEnvelope($item['envelope'] ?? null, $userId, 1);
                $mutationId = $this->mutationId($item['idempotency_key'] ?? null); $digest = $this->digest($envelope, $mutationId);
                $row = $this->records->findForUpdate($userId, $envelope['record_id']);
                if ($row !== null) { if (!$this->sameMutation($row, $mutationId, $digest)) throw new HttpException(409, 'ENCRYPTED_RECORD_ALREADY_EXISTS', 'Encrypted record already exists'); $results[]=$this->output($row); continue; }
                $envelope['sync_sequence']=$this->records->nextSyncSequence($userId); $envelope['mutation_id']=$mutationId; $envelope['digest']=$digest;
                $this->records->insert($userId,$envelope); $this->records->appendChange($userId,$envelope); $results[]=$this->output($this->records->findForUpdate($userId,$envelope['record_id']) ?? $envelope);
            }
            foreach ($payload['updates'] as $item) {
                if (!is_array($item)) throw new HttpException(422, 'ENCRYPTED_RECORD_PAYLOAD_INVALID', 'Encrypted batch payload is structurally invalid');
                $expected=$this->expectedRevision($item['expected_revision'] ?? null); $raw=$item['envelope'] ?? null; $recordId=is_array($raw)?(string)($raw['record_id']??''):''; $this->validateRecordId($recordId);
                $envelope=$this->validateEnvelope($raw,$userId,$expected+1,$recordId); $mutationId=$this->mutationId($item['idempotency_key']??null); $digest=$this->digest($envelope,$mutationId); $row=$this->records->findForUpdate($userId,$recordId);
                if ($row===null) throw new HttpException(404,'ENCRYPTED_RECORD_NOT_FOUND','Encrypted record not found'); if ($this->sameMutation($row,$mutationId,$digest)) { $results[]=$this->output($row); continue; } if ((int)$row['is_deleted']===1 || (int)$row['record_revision']!==$expected) throw new HttpException(409,'ENCRYPTED_RECORD_REVISION_CONFLICT','Encrypted record revision conflict');
                $envelope['sync_sequence']=$this->records->nextSyncSequence($userId); $envelope['mutation_id']=$mutationId; $envelope['digest']=$digest; $this->records->update($userId,$recordId,$envelope); $this->records->appendChange($userId,$envelope); $results[]=$this->output($this->records->findForUpdate($userId,$recordId)??$envelope);
            }
            foreach ($payload['tombstones'] as $item) {
                if (!is_array($item)) throw new HttpException(422, 'ENCRYPTED_RECORD_PAYLOAD_INVALID', 'Encrypted batch payload is structurally invalid'); $recordId=(string)($item['record_id']??''); $this->validateRecordId($recordId); $expected=$this->expectedRevision($item['expected_revision']??null); $mutationId=$this->mutationId($item['idempotency_key']??null); $digest=hash('sha256',$recordId.'|'.$expected.'|'.$mutationId); $row=$this->records->findForUpdate($userId,$recordId);
                if ($row===null) throw new HttpException(404,'ENCRYPTED_RECORD_NOT_FOUND','Encrypted record not found'); if ($this->sameMutation($row,$mutationId,$digest)) { $results[]=$this->output($row); continue; } if ((int)$row['is_deleted']===1 || (int)$row['record_revision']!==$expected) throw new HttpException(409,'ENCRYPTED_RECORD_REVISION_CONFLICT','Encrypted record revision conflict'); $data=['vault_id'=>(string)$row['vault_id'],'record_id'=>$recordId,'envelope_version'=>(int)$row['envelope_version'],'record_revision'=>$expected+1,'sync_sequence'=>$this->records->nextSyncSequence($userId),'mutation_id'=>$mutationId,'digest'=>$digest,'is_deleted'=>1,'iv'=>null,'ciphertext'=>null]; $this->records->tombstone($userId,$recordId,$data); $this->records->appendChange($userId,$data); $results[]=$this->output($this->records->findForUpdate($userId,$recordId)??$data);
            }
            $result=['records'=>$results,'idempotent'=>false]; $insert=$this->pdo->prepare('INSERT INTO encrypted_record_batches (user_id,idempotency_key,result_json) VALUES (:user_id,:key,:result)'); $insert->execute([':user_id'=>$userId,':key'=>$batchKey,':result'=>json_encode($result,JSON_THROW_ON_ERROR)]); $this->pdo->commit(); return $result;
        } catch (\Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    private function guard(AuthContext $auth): int
    {
        $userId = $auth->userId();
        $state = $this->states->get($userId);
        if (in_array($state, [
            FinancialPrivacyState::VAULT_SETUP_REQUIRED,
            FinancialPrivacyState::MIGRATION_IN_PROGRESS,
            FinancialPrivacyState::MIGRATION_FAILED,
        ], true)) {
            throw new HttpException(409, 'PRIVACY_STATE_CONFLICT', 'Encrypted financial authority is not enabled for this account');
        }
        if ($this->vaults->findByUser($userId) === null) throw new HttpException(404, 'VAULT_NOT_INITIALIZED', 'Vault is not initialized');
        return $userId;
    }

    /** @return array<string,mixed> */
    private function validateEnvelope(mixed $value, int $userId, int $revision, ?string $recordId = null): array
    {
        if (!is_array($value)) throw new HttpException(422, 'ENCRYPTED_RECORD_PAYLOAD_INVALID', 'Encrypted record payload is structurally invalid');
        $vault = $this->vaults->findByUser($userId);
        $vaultId = (string) ($value['vault_id'] ?? '');
        $givenRecordId = $value['record_id'] ?? null;
        if ($vault === null || $vaultId !== (string) $vault['vault_id'] || !is_string($givenRecordId)) throw new HttpException(422, 'ENCRYPTED_RECORD_PAYLOAD_INVALID', 'Encrypted record payload is structurally invalid');
        $this->validateRecordId($givenRecordId);
        if ($recordId !== null && $givenRecordId !== $recordId) throw new HttpException(422, 'ENCRYPTED_RECORD_PAYLOAD_INVALID', 'Encrypted record payload is structurally invalid');
        if ((int) ($value['envelope_version'] ?? 0) !== 1 || (int) ($value['record_revision'] ?? 0) !== $revision) {
            if ((int) ($value['envelope_version'] ?? 0) !== 1) throw new HttpException(422, 'ENCRYPTED_RECORD_VERSION_UNSUPPORTED', 'Encrypted record envelope version is unsupported');
            throw new HttpException(422, 'ENCRYPTED_RECORD_PAYLOAD_INVALID', 'Encrypted record payload is structurally invalid');
        }
        $iv = $this->decode($value['iv'] ?? null, 12, 12);
        $ciphertext = $this->decode($value['ciphertext'] ?? null, 16, self::MAX_CIPHERTEXT_BYTES);
        return ['vault_id' => $vaultId, 'record_id' => $givenRecordId, 'envelope_version' => 1, 'record_revision' => $revision, 'iv' => $iv, 'ciphertext' => $ciphertext];
    }

    private function validateRecordId(string $recordId): void { if (preg_match('/^(?:rec_[A-Za-z0-9_-]{8,90}|mig:[A-Za-z0-9_:-]{1,90})$/', $recordId) !== 1) throw new HttpException(422, 'ENCRYPTED_RECORD_PAYLOAD_INVALID', 'Encrypted record payload is structurally invalid'); }
    private function expectedRevision(mixed $value): int { if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) throw new HttpException(422, 'ENCRYPTED_RECORD_PAYLOAD_INVALID', 'Encrypted record payload is structurally invalid'); return (int) $value; }
    private function mutationId(mixed $value): string { if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{12,128}$/', $value) !== 1) throw new HttpException(422, 'ENCRYPTED_RECORD_PAYLOAD_INVALID', 'Encrypted record payload is structurally invalid'); return $value; }
    private function cursor(mixed $value): int { if ($value === null || $value === '') return 0; if (!is_string($value) || preg_match('/^(0|[1-9][0-9]{0,18})$/', $value) !== 1) throw new HttpException(422, 'ENCRYPTED_SYNC_CURSOR_INVALID', 'Sync cursor is invalid'); return (int) $value; }
    private function limit(mixed $value): int { if ($value === null || $value === '') return 50; $limit = filter_var($value, FILTER_VALIDATE_INT); if ($limit === false || $limit < 1 || $limit > self::MAX_SYNC_LIMIT) throw new HttpException(422, 'ENCRYPTED_SYNC_CURSOR_INVALID', 'Sync limit is invalid'); return (int) $limit; }
    private function decode(mixed $value, int $min, int $max): string { if (!is_string($value) || $value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) throw new HttpException(422, 'ENCRYPTED_RECORD_PAYLOAD_INVALID', 'Encrypted record payload is structurally invalid'); $normalized = strtr($value, '-_', '+/'); $padding = strlen($normalized) % 4; if ($padding) $normalized .= str_repeat('=', 4 - $padding); $decoded = base64_decode($normalized, true); if ($decoded === false || strlen($decoded) < $min || strlen($decoded) > $max) throw new HttpException(422, 'ENCRYPTED_RECORD_PAYLOAD_INVALID', 'Encrypted record payload is structurally invalid'); return $decoded; }
    private function digest(array $envelope, string $mutationId): string { return hash('sha256', $envelope['vault_id'] . '|' . $envelope['record_id'] . '|' . $envelope['record_revision'] . '|' . $envelope['iv'] . '|' . $envelope['ciphertext'] . '|' . $mutationId); }
    private function sameMutation(array $row, string $mutationId, string $digest): bool { return hash_equals((string) $row['last_mutation_id'], $mutationId) && hash_equals((string) $row['last_mutation_digest'], $digest); }
    /** @return array<string,mixed> */
    private function output(array $row): array { $out = ['vault_id' => (string) $row['vault_id'], 'record_id' => (string) $row['record_id'], 'envelope_version' => (int) $row['envelope_version'], 'record_revision' => (int) $row['record_revision'], 'sync_sequence' => (string) $row['sync_sequence'], 'deleted' => (bool) $row['is_deleted']]; if (!$out['deleted']) { $out['iv'] = rtrim(strtr(base64_encode((string) $row['iv']), '+/', '-_'), '='); $out['ciphertext'] = rtrim(strtr(base64_encode((string) $row['ciphertext']), '+/', '-_'), '='); } return $out; }
    private function auditMutation(Request $request, AuthContext $auth, string $action, array $row): void
    {
        $metadata = [
            'encryption_version' => (int) $row['envelope_version'],
            'revision' => (int) $row['record_revision'],
            'resource_id' => (string) $row['record_id'],
            'sync_sequence' => (int) $row['sync_sequence'],
            'result' => 'accepted',
        ];
        $this->audit->record(
            $request,
            $auth->userId(),
            $auth->authType,
            $action,
            'encrypted_record',
            (string) $row['record_id'],
            $metadata
        );
    }
}
