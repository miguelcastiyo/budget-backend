<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Support\Str;
use PDO;

final class PrivacyMigrationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function create(int $userId, int $sourceRevision): array
    {
        $migrationId = Str::randomId('mig');
        $stmt = $this->pdo->prepare(
            "INSERT INTO financial_privacy_migrations (migration_id, user_id, status, source_financial_revision) VALUES (:migration_id, :user_id, 'active', :source_revision)"
        );
        $stmt->execute([
            ':migration_id' => $migrationId,
            ':user_id' => $userId,
            ':source_revision' => $sourceRevision,
        ]);

        return $this->getByPublicId($userId, $migrationId) ?? throw new \RuntimeException('Migration run was not persisted');
    }

    /** @return array<string, mixed>|null */
    public function getActive(int $userId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT migration_id, user_id, status, source_financial_revision, encrypted_schema_version, failure_code, started_at, failed_at, completed_at, cancelled_at, created_at, updated_at FROM financial_privacy_migrations WHERE user_id = :user_id AND status = 'active' LIMIT 1");
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function getLatest(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT migration_id, user_id, status, source_financial_revision, encrypted_schema_version, failure_code, started_at, failed_at, completed_at, cancelled_at, created_at, updated_at FROM financial_privacy_migrations WHERE user_id=:user_id ORDER BY id DESC LIMIT 1');
        $stmt->execute([':user_id'=>$userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function getByPublicId(int $userId, string $migrationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT migration_id, user_id, status, source_financial_revision, encrypted_schema_version, failure_code, started_at, failed_at, completed_at, cancelled_at, created_at, updated_at FROM financial_privacy_migrations WHERE user_id = :user_id AND migration_id = :migration_id LIMIT 1');
        $stmt->execute([':user_id' => $userId, ':migration_id' => $migrationId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function markFailed(int $userId, string $migrationId, string $failureCode): void
    {
        $stmt = $this->pdo->prepare("UPDATE financial_privacy_migrations SET status = 'failed', failure_code = :failure_code, failed_at = UTC_TIMESTAMP() WHERE user_id = :user_id AND migration_id = :migration_id AND status = 'active'");
        $stmt->execute([':failure_code' => $failureCode, ':user_id' => $userId, ':migration_id' => $migrationId]);
    }

    public function markCompleted(int $userId, string $migrationId): void
    {
        $stmt = $this->pdo->prepare("UPDATE financial_privacy_migrations SET status = 'completed', completed_at = UTC_TIMESTAMP() WHERE user_id = :user_id AND migration_id = :migration_id AND status = 'active'");
        $stmt->execute([':user_id' => $userId, ':migration_id' => $migrationId]);
    }

    public function markCancelled(int $userId, string $migrationId): void
    {
        $stmt = $this->pdo->prepare("UPDATE financial_privacy_migrations SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP() WHERE user_id = :user_id AND migration_id = :migration_id AND status = 'active'");
        $stmt->execute([':user_id' => $userId, ':migration_id' => $migrationId]);
    }
}
