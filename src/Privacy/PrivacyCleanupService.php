<?php

declare(strict_types=1);

namespace App\Privacy;

use PDO;
use Throwable;

final class PrivacyCleanupService
{
    /** Child rows precede their referenced financial parents. */
    private const TABLES = [
        'fund_entries', 'monthly_savings_allocations', 'monthly_closeout_allocations',
        'monthly_closeouts', 'recurring_expense_occurrences', 'transactions',
        'recurring_expenses', 'csv_import_runs', 'budget_settings_versions',
        'budget_settings', 'funds', 'contexts', 'cards', 'tags',
    ];

    public function __construct(private readonly PDO $pdo, private readonly PrivacyCleanupRepository $jobs)
    {
    }

    /** @return array<string,mixed>|null */
    public function runNext(): ?array
    {
        $job = $this->jobs->claimNext();
        if ($job === null) return null;
        try {
            $this->pdo->beginTransaction();
            foreach (self::TABLES as $table) {
                if (!$this->tableExists($table)) continue;
                $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE user_id=:user_id");
                $stmt->execute([':user_id'=>(int)$job['user_id']]);
            }
            $this->pdo->commit();
            $this->jobs->markCompleted((string)$job['cleanup_job_id']);
            return $this->jobs->getByPublicId((int)$job['user_id'], (string)$job['cleanup_job_id']);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->jobs->markRetry((string)$job['cleanup_job_id'], 'PLAINTEXT_CLEANUP_FAILED', gmdate('Y-m-d H:i:s', time() + 60));
            throw $e;
        }
    }

    /** @return list<string> */
    public static function protectedTables(): array
    {
        return self::TABLES;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table');
        $stmt->execute([':table'=>$table]);
        return (int)$stmt->fetchColumn() === 1;
    }
}
