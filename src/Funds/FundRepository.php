<?php

declare(strict_types=1);

namespace App\Funds;

use App\Support\Str;
use PDO;

final class FundRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function listFunds(int $userId, string $status): array
    {
        $conditions = ['f.user_id = :user_id'];
        $params = [':user_id' => $userId];

        if ($status === 'active') {
            $conditions[] = "f.status = 'active'";
        } elseif ($status === 'archived') {
            $conditions[] = "f.status = 'archived'";
        }

        $sql = 'SELECT f.*
                FROM funds f
                WHERE ' . implode(' AND ', $conditions) . '
                ORDER BY
                  CASE WHEN f.status = \'active\' THEN 0 ELSE 1 END ASC,
                  f.sort_order ASC,
                  LOWER(f.name) ASC,
                  f.id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return array<string,mixed>|null */
    public function findFundByPublicId(int $userId, string $fundPublicId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM funds
             WHERE user_id = :user_id
               AND fund_id = :fund_id
             LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':fund_id' => $fundPublicId,
        ]);

        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function activeNameExists(int $userId, string $name, ?int $excludeId = null): bool
    {
        $sql = 'SELECT id
                FROM funds
                WHERE user_id = :user_id
                  AND name = :name
                  AND status = \'active\'';
        $params = [
            ':user_id' => $userId,
            ':name' => $name,
        ];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() !== false;
    }

    public function createFund(
        int $userId,
        string $name,
        string $fundType,
        ?string $goalAmount,
        ?string $targetMonthStart,
        ?string $notes,
        int $sortOrder
    ): array {
        $fundPublicId = Str::randomId('fund');
        $stmt = $this->pdo->prepare(
            'INSERT INTO funds (
               fund_id,
               user_id,
               name,
               fund_type,
               goal_amount,
               target_month,
               notes,
               status,
               sort_order,
               archived_at
             ) VALUES (
               :fund_id,
               :user_id,
               :name,
               :fund_type,
               :goal_amount,
               :target_month,
               :notes,
               \'active\',
               :sort_order,
               NULL
             )'
        );
        $stmt->execute([
            ':fund_id' => $fundPublicId,
            ':user_id' => $userId,
            ':name' => $name,
            ':fund_type' => $fundType,
            ':goal_amount' => $goalAmount,
            ':target_month' => $targetMonthStart,
            ':notes' => $notes,
            ':sort_order' => $sortOrder,
        ]);

        return $this->findFundByPublicId($userId, $fundPublicId) ?? [];
    }

    public function updateFund(
        int $fundDbId,
        int $userId,
        string $name,
        string $fundType,
        ?string $goalAmount,
        ?string $targetMonthStart,
        ?string $notes,
        int $sortOrder
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE funds
             SET name = :name,
                 fund_type = :fund_type,
                 goal_amount = :goal_amount,
                 target_month = :target_month,
                 notes = :notes,
                 sort_order = :sort_order,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND user_id = :user_id'
        );
        $stmt->execute([
            ':id' => $fundDbId,
            ':user_id' => $userId,
            ':name' => $name,
            ':fund_type' => $fundType,
            ':goal_amount' => $goalAmount,
            ':target_month' => $targetMonthStart,
            ':notes' => $notes,
            ':sort_order' => $sortOrder,
        ]);
    }

    public function archiveFund(int $fundDbId, int $userId, string $archivedAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE funds
             SET status = \'archived\',
                 archived_at = :archived_at,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND user_id = :user_id'
        );
        $stmt->execute([
            ':id' => $fundDbId,
            ':user_id' => $userId,
            ':archived_at' => $archivedAt,
        ]);
    }

    public function restoreFund(int $fundDbId, int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE funds
             SET status = \'active\',
                 archived_at = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND user_id = :user_id'
        );
        $stmt->execute([
            ':id' => $fundDbId,
            ':user_id' => $userId,
        ]);
    }

    public function insertEntry(
        int $userId,
        int $fundDbId,
        string $entryDate,
        string $entryType,
        string $direction,
        string $amount,
        string $sourceType,
        ?int $sourceTransactionId,
        ?int $sourceCloseoutId,
        ?int $sourceCloseoutAllocationId,
        ?string $note
    ): string {
        $entryPublicId = Str::randomId('fent');
        $stmt = $this->pdo->prepare(
            'INSERT INTO fund_entries (
               fund_entry_id,
               user_id,
               fund_id,
               entry_date,
               entry_type,
               direction,
               amount,
               source_type,
               source_transaction_id,
               source_closeout_id,
               source_closeout_allocation_id,
               note,
               voided_at,
               void_reason,
               deleted_at
             ) VALUES (
               :fund_entry_id,
               :user_id,
               :fund_id,
               :entry_date,
               :entry_type,
               :direction,
               :amount,
               :source_type,
               :source_transaction_id,
               :source_closeout_id,
               :source_closeout_allocation_id,
               :note,
               NULL,
               NULL,
               NULL
             )'
        );
        $stmt->execute([
            ':fund_entry_id' => $entryPublicId,
            ':user_id' => $userId,
            ':fund_id' => $fundDbId,
            ':entry_date' => $entryDate,
            ':entry_type' => $entryType,
            ':direction' => $direction,
            ':amount' => $amount,
            ':source_type' => $sourceType,
            ':source_transaction_id' => $sourceTransactionId,
            ':source_closeout_id' => $sourceCloseoutId,
            ':source_closeout_allocation_id' => $sourceCloseoutAllocationId,
            ':note' => $note,
        ]);

        return $entryPublicId;
    }

    /** @return array<string,mixed>|null */
    public function findEntryByPublicId(int $userId, int $fundDbId, string $entryPublicId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fe.*, f.fund_id AS fund_public_id, mc.closeout_id AS closeout_public_id
             FROM fund_entries fe
             JOIN funds f ON f.id = fe.fund_id AND f.user_id = fe.user_id
             LEFT JOIN monthly_closeouts mc ON mc.id = fe.source_closeout_id
             WHERE fe.user_id = :user_id
               AND fe.fund_id = :fund_id
               AND fe.fund_entry_id = :fund_entry_id
             LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':fund_id' => $fundDbId,
            ':fund_entry_id' => $entryPublicId,
        ]);

        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function findActiveEntryByTransactionId(int $userId, int $transactionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fe.*, f.fund_id AS fund_public_id
             FROM fund_entries fe
             JOIN funds f ON f.id = fe.fund_id AND f.user_id = fe.user_id
             WHERE fe.user_id = :user_id
               AND fe.source_transaction_id = :transaction_id
               AND fe.deleted_at IS NULL
               AND fe.voided_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':transaction_id' => $transactionId,
        ]);

        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function listEntries(int $userId, int $fundDbId, array $filters, int $page, int $pageSize): array
    {
        [$whereSql, $params] = $this->entryFiltersSql($userId, $fundDbId, $filters);
        $sql = 'SELECT fe.*, mc.closeout_id AS closeout_public_id
                FROM fund_entries fe
                LEFT JOIN monthly_closeouts mc ON mc.id = fe.source_closeout_id
                WHERE ' . $whereSql . '
                ORDER BY fe.entry_date DESC, fe.id DESC
                LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    public function countEntries(int $userId, int $fundDbId, array $filters): int
    {
        [$whereSql, $params] = $this->entryFiltersSql($userId, $fundDbId, $filters);
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS total
             FROM fund_entries fe
             WHERE ' . $whereSql
        );
        $stmt->execute($params);

        return (int) (($stmt->fetch()['total'] ?? 0));
    }

    /** @return list<array<string,mixed>> */
    public function recentEntries(int $userId, int $fundDbId, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fe.*, mc.closeout_id AS closeout_public_id
             FROM fund_entries fe
             LEFT JOIN monthly_closeouts mc ON mc.id = fe.source_closeout_id
             WHERE fe.user_id = :user_id
               AND fe.fund_id = :fund_id
               AND fe.deleted_at IS NULL
               AND fe.voided_at IS NULL
             ORDER BY fe.entry_date DESC, fe.id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':fund_id', $fundDbId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return array<string,string> */
    public function sourceBreakdown(int $userId, int $fundDbId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT source_type,
                    COALESCE(SUM(CASE WHEN direction = \'in\' THEN amount ELSE -amount END), 0.00) AS total
             FROM fund_entries
             WHERE user_id = :user_id
               AND fund_id = :fund_id
               AND deleted_at IS NULL
               AND voided_at IS NULL
             GROUP BY source_type'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':fund_id' => $fundDbId,
        ]);

        $breakdown = [
            'month_closeout' => '0.00',
            'transaction' => '0.00',
            'manual' => '0.00',
            'starting_balance' => '0.00',
            'correction' => '0.00',
        ];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row) && isset($breakdown[(string) $row['source_type']])) {
                $breakdown[(string) $row['source_type']] = $this->fmt((string) $row['total']);
            }
        }

        return $breakdown;
    }

    public function updateEditableEntry(int $entryDbId, int $userId, string $entryDate, string $entryType, string $direction, string $amount, ?string $note): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE fund_entries
             SET entry_date = :entry_date,
                 entry_type = :entry_type,
                 direction = :direction,
                 amount = :amount,
                 note = :note,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND user_id = :user_id'
        );
        $stmt->execute([
            ':id' => $entryDbId,
            ':user_id' => $userId,
            ':entry_date' => $entryDate,
            ':entry_type' => $entryType,
            ':direction' => $direction,
            ':amount' => $amount,
            ':note' => $note,
        ]);
    }

    public function softDeleteEditableEntry(int $entryDbId, int $userId, string $deletedAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE fund_entries
             SET deleted_at = :deleted_at,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND user_id = :user_id'
        );
        $stmt->execute([
            ':id' => $entryDbId,
            ':user_id' => $userId,
            ':deleted_at' => $deletedAt,
        ]);
    }

    public function syncEntryFromTransaction(int $entryDbId, int $userId, string $entryDate, string $amount, ?string $note): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE fund_entries
             SET entry_date = :entry_date,
                 amount = :amount,
                 note = :note,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND user_id = :user_id'
        );
        $stmt->execute([
            ':id' => $entryDbId,
            ':user_id' => $userId,
            ':entry_date' => $entryDate,
            ':amount' => $amount,
            ':note' => $note,
        ]);
    }

    public function voidEntry(int $entryDbId, int $userId, string $voidReason, string $voidedAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE fund_entries
             SET voided_at = :voided_at,
                 void_reason = :void_reason,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND user_id = :user_id
               AND voided_at IS NULL
               AND deleted_at IS NULL'
        );
        $stmt->execute([
            ':id' => $entryDbId,
            ':user_id' => $userId,
            ':voided_at' => $voidedAt,
            ':void_reason' => $voidReason,
        ]);
    }

    public function voidActiveEntriesForCloseout(int $userId, int $closeoutDbId, string $voidReason, string $voidedAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE fund_entries
             SET voided_at = :voided_at,
                 void_reason = :void_reason,
                 updated_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id
               AND source_closeout_id = :closeout_id
               AND source_type = \'month_closeout\'
               AND voided_at IS NULL
               AND deleted_at IS NULL'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':closeout_id' => $closeoutDbId,
            ':voided_at' => $voidedAt,
            ':void_reason' => $voidReason,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function activeFundAllocationsForCloseout(int $closeoutDbId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT mca.*, f.fund_id AS fund_public_id, f.name AS fund_name
             FROM monthly_closeout_allocations mca
             JOIN funds f ON f.id = mca.fund_id AND f.user_id = mca.user_id
             WHERE mca.closeout_id = :closeout_id
               AND mca.allocation_type = \'fund\'
             ORDER BY mca.id ASC'
        );
        $stmt->execute([':closeout_id' => $closeoutDbId]);

        $rows = $stmt->fetchAll();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    public function findActiveTransactionForLink(int $userId, int $transactionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, transaction_date, amount, category, notes
             FROM transactions
             WHERE id = :id
               AND user_id = :user_id
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([
            ':id' => $transactionId,
            ':user_id' => $userId,
        ]);

        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string,string> */
    public function balancesByFund(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fund_id,
                    COALESCE(SUM(CASE WHEN direction = \'in\' THEN amount ELSE -amount END), 0.00) AS balance
             FROM fund_entries
             WHERE user_id = :user_id
               AND deleted_at IS NULL
               AND voided_at IS NULL
             GROUP BY fund_id'
        );
        $stmt->execute([':user_id' => $userId]);

        $balances = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $balances[(string) $row['fund_id']] = $this->fmt((string) $row['balance']);
            }
        }

        return $balances;
    }

    public function balanceForFund(int $userId, int $fundDbId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(CASE WHEN direction = \'in\' THEN amount ELSE -amount END), 0.00) AS balance
             FROM fund_entries
             WHERE user_id = :user_id
               AND fund_id = :fund_id
               AND deleted_at IS NULL
               AND voided_at IS NULL'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':fund_id' => $fundDbId,
        ]);

        return $this->fmt((string) (($stmt->fetch()['balance'] ?? '0')));
    }

    /** @return array<string,mixed> */
    public function closeoutSummary(int $userId, int $year): array
    {
        $yearStart = sprintf('%04d-01-01', $year);
        $yearEnd = sprintf('%04d-12-31', $year);

        $monthlyStmt = $this->pdo->prepare(
            'SELECT mc.id,
                    mc.month,
                    mc.result_type,
                    mc.surplus_amount,
                    COALESCE(SUM(CASE WHEN mca.allocation_type = \'fund\' THEN mca.amount ELSE 0 END), 0.00) AS allocated_to_funds
             FROM monthly_closeouts mc
             LEFT JOIN monthly_closeout_allocations mca ON mca.closeout_id = mc.id
             WHERE mc.user_id = :user_id
               AND mc.status = \'closed\'
               AND mc.month BETWEEN :year_start AND :year_end
             GROUP BY mc.id
             ORDER BY mc.month ASC, mc.id ASC'
        );
        $monthlyStmt->execute([
            ':user_id' => $userId,
            ':year_start' => $yearStart,
            ':year_end' => $yearEnd,
        ]);

        $months = [];
        $totalCloseout = 0.0;
        $unassigned = 0.0;
        foreach ($monthlyStmt->fetchAll() as $row) {
            if (!is_array($row) || (string) $row['result_type'] !== 'surplus') {
                continue;
            }

            $fundAllocationsStmt = $this->pdo->prepare(
                'SELECT f.fund_id AS fund_public_id, f.name AS fund_name, mca.amount
                 FROM monthly_closeout_allocations mca
                 JOIN funds f ON f.id = mca.fund_id AND f.user_id = mca.user_id
                 WHERE mca.closeout_id = :closeout_id
                   AND mca.allocation_type = \'fund\'
                 ORDER BY mca.id ASC'
            );
            $fundAllocationsStmt->execute([':closeout_id' => $row['id']]);

            $fundAllocations = [];
            foreach ($fundAllocationsStmt->fetchAll() as $allocation) {
                if (is_array($allocation)) {
                    $fundAllocations[] = [
                        'fund_id' => (string) $allocation['fund_public_id'],
                        'fund_name' => (string) $allocation['fund_name'],
                        'amount' => $this->fmt((string) $allocation['amount']),
                    ];
                }
            }

            $surplus = (float) $row['surplus_amount'];
            $allocatedToFunds = (float) $row['allocated_to_funds'];
            $totalCloseout += $allocatedToFunds;
            $unassigned += max($surplus - $allocatedToFunds, 0.0);

            $months[] = [
                'month' => substr((string) $row['month'], 0, 7),
                'result_type' => (string) $row['result_type'],
                'surplus_amount' => $this->fmt((string) $row['surplus_amount']),
                'allocated_to_funds' => $this->fmt((string) $row['allocated_to_funds']),
                'unassigned_amount' => $this->fmt((string) max($surplus - $allocatedToFunds, 0.0)),
                'fund_allocations' => $fundAllocations,
            ];
        }

        $fundStmt = $this->pdo->prepare(
            'SELECT f.id,
                    f.fund_id,
                    f.name,
                    f.fund_type,
                    f.goal_amount,
                    COALESCE(SUM(fe.amount), 0.00) AS closeout_contributed,
                    COUNT(fe.id) AS closeout_count
             FROM funds f
             JOIN fund_entries fe ON fe.fund_id = f.id
               AND fe.user_id = f.user_id
               AND fe.source_type = \'month_closeout\'
               AND fe.deleted_at IS NULL
               AND fe.voided_at IS NULL
               AND fe.entry_date BETWEEN :year_start AND :year_end
             WHERE f.user_id = :user_id
             GROUP BY f.id
             ORDER BY closeout_contributed DESC, LOWER(f.name) ASC, f.id ASC'
        );
        $fundStmt->execute([
            ':user_id' => $userId,
            ':year_start' => $yearStart,
            ':year_end' => $yearEnd,
        ]);
        $fundRows = $fundStmt->fetchAll();

        return [
            'total_closeout_contributed' => $this->fmt((string) $totalCloseout),
            'unassigned_closeout_total' => $this->fmt((string) $unassigned),
            'fund_rows' => is_array($fundRows) ? array_values(array_filter($fundRows, 'is_array')) : [],
            'months' => $months,
        ];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function entryFiltersSql(int $userId, int $fundDbId, array $filters): array
    {
        $where = [
            'fe.user_id = :user_id',
            'fe.fund_id = :fund_id',
            'fe.deleted_at IS NULL',
            'fe.voided_at IS NULL',
        ];
        $params = [
            ':user_id' => $userId,
            ':fund_id' => $fundDbId,
        ];

        if (($filters['source_type'] ?? null) !== null) {
            $where[] = 'fe.source_type = :source_type';
            $params[':source_type'] = $filters['source_type'];
        }
        if (($filters['entry_type'] ?? null) !== null) {
            $where[] = 'fe.entry_type = :entry_type';
            $params[':entry_type'] = $filters['entry_type'];
        }
        if (($filters['date_from'] ?? null) !== null) {
            $where[] = 'fe.entry_date >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }
        if (($filters['date_to'] ?? null) !== null) {
            $where[] = 'fe.entry_date <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        return [implode(' AND ', $where), $params];
    }

    private function fmt(string $decimal): string
    {
        return number_format((float) $decimal, 2, '.', '');
    }
}
