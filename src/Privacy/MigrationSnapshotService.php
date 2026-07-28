<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Http\HttpException;
use PDO;

/** Builds the one coherent, migration-scoped plaintext source snapshot. */
final class MigrationSnapshotService
{
    public const SNAPSHOT_SCHEMA_VERSION = 'phase5_snapshot_v1';

    /** @var array<string, string> */
    private const SOURCES = [
        'tags' => 'tags',
        'cards' => 'cards',
        'contexts' => 'contexts',
        'funds' => 'funds',
        'monthly_savings_allocations' => 'monthly_savings_allocations',
        'recurring_expenses' => 'recurring_expenses',
        'budget_settings' => 'budget_settings',
        'budget_settings_versions' => 'budget_settings_versions',
        'transactions' => 'transactions',
        'recurring_expense_occurrences' => 'recurring_expense_occurrences',
        'fund_entries' => 'fund_entries',
        'monthly_closeouts' => 'monthly_closeouts',
        'monthly_closeout_allocations' => 'monthly_closeout_allocations',
        'csv_import_runs' => 'csv_import_runs',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly FinancialRevisionService $revisions,
        private readonly PrivacyMigrationRepository $migrations
    ) {
    }

    /** @return array<string, mixed> */
    public function snapshot(int $userId, string $migrationId): array
    {
        $migration = $this->migrations->getByPublicId($userId, $migrationId);
        if ($migration === null || (string) $migration['status'] !== 'active') {
            throw new HttpException(409, 'MIGRATION_STATE_INVALID', 'Migration is not active');
        }
        $sourceRevision = (int) $migration['source_financial_revision'];
        if ($this->revisions->get($userId) !== $sourceRevision) {
            throw new HttpException(409, 'MIGRATION_SOURCE_REVISION_CHANGED', 'The migration source revision changed');
        }

        $this->pdo->beginTransaction();
        try {
            $revision = $this->revisions->get($userId);
            if ($revision !== $sourceRevision) {
                throw new HttpException(409, 'MIGRATION_SOURCE_REVISION_CHANGED', 'The migration source revision changed');
            }
            $collections = [];
            $manifest = [];
            foreach (self::SOURCES as $family => $table) {
                $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE user_id = :user_id ORDER BY id ASC");
                $stmt->execute([':user_id' => $userId]);
                $rows = $stmt->fetchAll();
                $collections[$family] = $rows;
                $ids = [];
                foreach ($rows as $row) {
                    $ids[] = (string) ($row['id'] ?? '');
                }
                $manifest[$family] = ['count' => count($rows), 'source_ids' => $ids];
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'migration_run_id' => $migrationId,
            'source_financial_revision' => $sourceRevision,
            'snapshot_schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
            'source_manifest' => [
                'manifest_version' => 'phase5_source_manifest_v1',
                'families' => $manifest,
                'relationship_count' => $this->relationshipCount($collections),
            ],
            'collections' => $collections,
        ];
    }

    /** @param array<string, list<array<string,mixed>>> $collections */
    private function relationshipCount(array $collections): int
    {
        $count = 0;
        foreach ($collections as $rows) {
            foreach ($rows as $row) {
                foreach ($row as $key => $value) {
                    if ($value !== null && (str_ends_with((string) $key, '_id') || str_ends_with((string) $key, '_ids'))) {
                        $count++;
                    }
                }
            }
        }
        return $count;
    }
}
