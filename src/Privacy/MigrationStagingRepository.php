<?php

declare(strict_types=1);

namespace App\Privacy;

use PDO;

final class MigrationStagingRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string,mixed> $record */
    public function upsertRecord(string $migrationId, int $userId, array $record): void
    {
        $sql = 'INSERT INTO encrypted_migration_records (migration_id,user_id,target_record_id,record_family,record_schema_version,envelope_version,iv,ciphertext) VALUES (:migration_id,:user_id,:target_record_id,:record_family,:record_schema_version,:envelope_version,:iv,:ciphertext) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),record_family=VALUES(record_family),record_schema_version=VALUES(record_schema_version),envelope_version=VALUES(envelope_version),iv=VALUES(iv),ciphertext=VALUES(ciphertext)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':migration_id', $migrationId);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':target_record_id', $record['target_record_id']);
        $stmt->bindValue(':record_family', $record['record_family']);
        $stmt->bindValue(':record_schema_version', $record['record_schema_version']);
        $stmt->bindValue(':envelope_version', (int) $record['envelope_version'], PDO::PARAM_INT);
        $stmt->bindValue(':iv', $record['iv'], PDO::PARAM_LOB);
        $stmt->bindValue(':ciphertext', $record['ciphertext'], PDO::PARAM_LOB);
        $stmt->execute();
    }

    /** @param array<string,mixed> $manifest */
    public function putManifest(string $migrationId, int $userId, array $manifest, int $sourceRevision): void
    {
        $json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $stmt = $this->pdo->prepare('INSERT INTO encrypted_migration_manifests (migration_id,user_id,manifest_version,snapshot_schema_version,source_financial_revision,target_count,relationship_count,manifest_json,manifest_hash) VALUES (:migration_id,:user_id,:manifest_version,:snapshot_version,:revision,:target_count,:relationship_count,:manifest_json,:manifest_hash) ON DUPLICATE KEY UPDATE manifest_version=VALUES(manifest_version),snapshot_schema_version=VALUES(snapshot_schema_version),source_financial_revision=VALUES(source_financial_revision),target_count=VALUES(target_count),relationship_count=VALUES(relationship_count),manifest_json=VALUES(manifest_json),manifest_hash=VALUES(manifest_hash),finalized_at=NULL,verified_at=NULL');
        $stmt->execute([
            ':migration_id' => $migrationId, ':user_id' => $userId,
            ':manifest_version' => (string) ($manifest['manifest_version'] ?? 'phase5_target_manifest_v1'),
            ':snapshot_version' => (string) ($manifest['snapshot_schema_version'] ?? MigrationSnapshotService::SNAPSHOT_SCHEMA_VERSION),
            ':revision' => $sourceRevision, ':target_count' => count($manifest['targets'] ?? []),
            ':relationship_count' => (int) ($manifest['relationship_count'] ?? 0), ':manifest_json' => $json,
            ':manifest_hash' => hash('sha256', $json),
        ]);
    }

    public function counts(string $migrationId, int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM encrypted_migration_records WHERE migration_id=:migration_id AND user_id=:user_id');
        $stmt->execute([':migration_id'=>$migrationId, ':user_id'=>$userId]);
        $manifest = $this->pdo->prepare('SELECT target_count,relationship_count,manifest_hash,finalized_at,verified_at FROM encrypted_migration_manifests WHERE migration_id=:migration_id AND user_id=:user_id LIMIT 1');
        $manifest->execute([':migration_id'=>$migrationId, ':user_id'=>$userId]);
        $row = $manifest->fetch();
        return ['staged_count'=>(int)$stmt->fetchColumn(), 'expected_count'=>is_array($row)?(int)$row['target_count']:null, 'relationship_count'=>is_array($row)?(int)$row['relationship_count']:null, 'manifest_hash'=>is_array($row)?$row['manifest_hash']:null, 'finalized'=>is_array($row)&&$row['finalized_at']!==null, 'verified'=>is_array($row)&&$row['verified_at']!==null];
    }

    public function markVerified(string $migrationId, int $userId): void
    {
        $this->pdo->prepare('UPDATE encrypted_migration_manifests SET finalized_at=COALESCE(finalized_at,UTC_TIMESTAMP()), verified_at=UTC_TIMESTAMP() WHERE migration_id=:migration_id AND user_id=:user_id')->execute([':migration_id'=>$migrationId,':user_id'=>$userId]);
    }

    /** @return array<string,mixed>|null */
    public function manifest(string $migrationId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT manifest_json,target_count,relationship_count FROM encrypted_migration_manifests WHERE migration_id=:migration_id AND user_id=:user_id LIMIT 1');
        $stmt->execute([':migration_id'=>$migrationId, ':user_id'=>$userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) return null;
        $decoded = json_decode((string) $row['manifest_json'], true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @return array<string,array{record_family:string,record_schema_version:string}> */
    public function targetMetadata(string $migrationId, int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT target_record_id,record_family,record_schema_version FROM encrypted_migration_records WHERE migration_id=:migration_id AND user_id=:user_id ORDER BY target_record_id ASC');
        $stmt->execute([':migration_id'=>$migrationId, ':user_id'=>$userId]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) $result[(string)$row['target_record_id']]=['record_family'=>(string)$row['record_family'],'record_schema_version'=>(string)$row['record_schema_version']];
        return $result;
    }

    public function deleteForMigration(string $migrationId, int $userId): void
    {
        $this->pdo->prepare('DELETE FROM encrypted_migration_records WHERE migration_id=:migration_id AND user_id=:user_id')->execute([':migration_id'=>$migrationId,':user_id'=>$userId]);
        $this->pdo->prepare('DELETE FROM encrypted_migration_manifests WHERE migration_id=:migration_id AND user_id=:user_id')->execute([':migration_id'=>$migrationId,':user_id'=>$userId]);
    }
}
