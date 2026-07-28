<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Http\HttpException;
use PDO;
use Throwable;

final class PrivacyMigrationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly FinancialPrivacyStateService $states,
        private readonly FinancialRevisionService $revisions,
        private readonly PrivacyMigrationRepository $migrations
    ) {
    }

    /** @return array<string, mixed> */
    public function createInternal(int $userId): array
    {
        $this->pdo->beginTransaction();
        try {
            $this->states->transitionInTransaction($userId, FinancialPrivacyState::MIGRATION_IN_PROGRESS);
            $revision = $this->revisions->get($userId);
            $migration = $this->migrations->create($userId, $revision);
            $this->pdo->commit();
            return $migration;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof \PDOException && ((int) $e->errorInfo[1] ?? 0) === 1062) {
                throw new HttpException(409, 'ACTIVE_MIGRATION_EXISTS', 'An active migration already exists');
            }
            throw $e;
        }
    }

    public function failInternal(int $userId, string $migrationId, string $failureCode): void
    {
        $this->pdo->beginTransaction();
        try {
            $migration = $this->migrations->getByPublicId($userId, $migrationId);
            if ($migration === null) {
                throw new HttpException(404, 'MIGRATION_NOT_FOUND', 'Migration run not found');
            }
            $this->migrations->markFailed($userId, $migrationId, $failureCode);
            $this->states->transitionInTransaction($userId, FinancialPrivacyState::MIGRATION_FAILED);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
