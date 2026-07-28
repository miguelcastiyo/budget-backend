<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Support\Str;
use PDO;

final class PrivacyCleanupRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function createPending(int $userId, string $migrationId): array
    {
        $existing = $this->getForMigration($userId, $migrationId);
        if ($existing !== null) return $existing;
        $jobId = Str::randomId('clean');
        try {
            $stmt = $this->pdo->prepare("INSERT INTO financial_privacy_cleanup_jobs (cleanup_job_id, user_id, migration_id, status) VALUES (:job_id, :user_id, :migration_id, 'pending')");
            $stmt->execute([':job_id' => $jobId, ':user_id' => $userId, ':migration_id' => $migrationId]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? '') !== '23000') throw $e;
            return $this->getForMigration($userId, $migrationId) ?? throw $e;
        }
        return $this->getByPublicId($userId, $jobId) ?? throw new \RuntimeException('Cleanup job was not persisted');
    }

    /** @return array<string, mixed>|null */
    public function getByPublicId(int $userId, string $jobId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT cleanup_job_id, user_id, migration_id, status, attempt_count, next_attempt_at, started_at, lease_expires_at, completed_at, last_failure_code, created_at, updated_at FROM financial_privacy_cleanup_jobs WHERE user_id = :user_id AND cleanup_job_id = :job_id LIMIT 1');
        $stmt->execute([':user_id' => $userId, ':job_id' => $jobId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function getForMigration(int $userId, string $migrationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT cleanup_job_id, user_id, migration_id, status, attempt_count, next_attempt_at, started_at, lease_expires_at, completed_at, last_failure_code, created_at, updated_at FROM financial_privacy_cleanup_jobs WHERE user_id = :user_id AND migration_id = :migration_id LIMIT 1');
        $stmt->execute([':user_id' => $userId, ':migration_id' => $migrationId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function claimNext(int $leaseSeconds = 900): ?array
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->query("SELECT cleanup_job_id, user_id, migration_id, status, attempt_count, next_attempt_at, started_at, lease_expires_at, completed_at, last_failure_code, created_at, updated_at FROM financial_privacy_cleanup_jobs WHERE (status IN ('pending', 'retry_pending') AND next_attempt_at <= UTC_TIMESTAMP()) OR (status = 'running' AND lease_expires_at IS NOT NULL AND lease_expires_at <= UTC_TIMESTAMP()) ORDER BY id ASC LIMIT 1 FOR UPDATE");
            $job = $stmt->fetch();
            if (!is_array($job)) {
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }
                return null;
            }

            $leaseExpires = gmdate('Y-m-d H:i:s', time() + max(1, $leaseSeconds));
            $update = $this->pdo->prepare("UPDATE financial_privacy_cleanup_jobs SET status = 'running', attempt_count = attempt_count + 1, started_at = UTC_TIMESTAMP(), lease_expires_at = :lease_expires_at WHERE cleanup_job_id = :job_id");
            $update->execute([':lease_expires_at' => $leaseExpires, ':job_id' => $job['cleanup_job_id']]);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $this->getByPublicId((int) $job['user_id'], (string) $job['cleanup_job_id']);
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function markRetry(string $jobId, string $failureCode, string $nextAttemptAt): void
    {
        $stmt = $this->pdo->prepare("UPDATE financial_privacy_cleanup_jobs SET status = 'retry_pending', next_attempt_at = :next_attempt_at, lease_expires_at = NULL, last_failure_code = :failure_code WHERE cleanup_job_id = :job_id AND status = 'running'");
        $stmt->execute([':next_attempt_at' => $nextAttemptAt, ':failure_code' => $failureCode, ':job_id' => $jobId]);
    }

    public function markCompleted(string $jobId): void
    {
        $stmt = $this->pdo->prepare("UPDATE financial_privacy_cleanup_jobs SET status = 'completed', completed_at = UTC_TIMESTAMP(), lease_expires_at = NULL WHERE cleanup_job_id = :job_id AND status = 'running'");
        $stmt->execute([':job_id' => $jobId]);
    }

    public function markFailed(string $jobId, string $failureCode): void
    {
        $stmt = $this->pdo->prepare("UPDATE financial_privacy_cleanup_jobs SET status = 'failed', lease_expires_at = NULL, last_failure_code = :failure_code WHERE cleanup_job_id = :job_id AND status IN ('running', 'retry_pending')");
        $stmt->execute([':failure_code' => $failureCode, ':job_id' => $jobId]);
    }
}
