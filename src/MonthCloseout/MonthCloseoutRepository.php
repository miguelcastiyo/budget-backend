<?php

declare(strict_types=1);

namespace App\MonthCloseout;

use PDO;

final class MonthCloseoutRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function findCloseoutByUserAndMonth(int $userId, string $monthStart): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT mc.*,
                    COALESCE(SUM(mca.amount), 0.00) AS allocated_amount
             FROM monthly_closeouts mc
             LEFT JOIN monthly_closeout_allocations mca ON mca.closeout_id = mc.id
             WHERE mc.user_id = :user_id
               AND mc.month = :month
             GROUP BY mc.id
             LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':month' => $monthStart,
        ]);

        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function listCloseouts(int $userId, array $filters): array
    {
        $conditions = ['mc.user_id = :user_id'];
        $params = [':user_id' => $userId];

        if (($filters['status'] ?? null) !== null) {
            $conditions[] = 'mc.status = :status';
            $params[':status'] = $filters['status'];
        }

        if (($filters['date_from'] ?? null) !== null) {
            $conditions[] = 'mc.month >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }

        if (($filters['date_to'] ?? null) !== null) {
            $conditions[] = 'mc.month <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        $sql = 'SELECT mc.*,
                       COALESCE(SUM(mca.amount), 0.00) AS allocated_amount
                FROM monthly_closeouts mc
                LEFT JOIN monthly_closeout_allocations mca ON mca.closeout_id = mc.id
                WHERE ' . implode(' AND ', $conditions) . '
                GROUP BY mc.id
                ORDER BY mc.month DESC, mc.id DESC
                LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', (int) ($filters['limit'] ?? 12), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    public function insertCloseout(array $snapshot): int
    {
        $sql = <<<'SQL'
INSERT INTO monthly_closeouts (
  closeout_id,
  user_id,
  month,
  status,
  result_type,
  budget_effective_month,
  budget_allocation_mode,
  monthly_income_snapshot,
  planned_needs,
  planned_wants,
  planned_savings,
  planned_total,
  actual_needs,
  actual_wants,
  actual_savings,
  actual_total,
  surplus_amount,
  deficit_amount,
  spending_surplus_amount,
  spending_deficit_amount,
  calculation_hash,
  notes,
  closed_at,
  reopened_at
)
VALUES (
  :closeout_id,
  :user_id,
  :month,
  :status,
  :result_type,
  :budget_effective_month,
  :budget_allocation_mode,
  :monthly_income_snapshot,
  :planned_needs,
  :planned_wants,
  :planned_savings,
  :planned_total,
  :actual_needs,
  :actual_wants,
  :actual_savings,
  :actual_total,
  :surplus_amount,
  :deficit_amount,
  :spending_surplus_amount,
  :spending_deficit_amount,
  :calculation_hash,
  :notes,
  :closed_at,
  :reopened_at
)
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($snapshot);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateCloseoutSnapshot(int $closeoutDbId, array $snapshot): void
    {
        $sql = <<<'SQL'
UPDATE monthly_closeouts
SET
  status = :status,
  result_type = :result_type,
  budget_effective_month = :budget_effective_month,
  budget_allocation_mode = :budget_allocation_mode,
  monthly_income_snapshot = :monthly_income_snapshot,
  planned_needs = :planned_needs,
  planned_wants = :planned_wants,
  planned_savings = :planned_savings,
  planned_total = :planned_total,
  actual_needs = :actual_needs,
  actual_wants = :actual_wants,
  actual_savings = :actual_savings,
  actual_total = :actual_total,
  surplus_amount = :surplus_amount,
  deficit_amount = :deficit_amount,
  spending_surplus_amount = :spending_surplus_amount,
  spending_deficit_amount = :spending_deficit_amount,
  calculation_hash = :calculation_hash,
  notes = :notes,
  closed_at = :closed_at,
  reopened_at = :reopened_at,
  updated_at = CURRENT_TIMESTAMP
WHERE id = :id
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $closeoutDbId,
            ':status' => $snapshot[':status'],
            ':result_type' => $snapshot[':result_type'],
            ':budget_effective_month' => $snapshot[':budget_effective_month'],
            ':budget_allocation_mode' => $snapshot[':budget_allocation_mode'],
            ':monthly_income_snapshot' => $snapshot[':monthly_income_snapshot'],
            ':planned_needs' => $snapshot[':planned_needs'],
            ':planned_wants' => $snapshot[':planned_wants'],
            ':planned_savings' => $snapshot[':planned_savings'],
            ':planned_total' => $snapshot[':planned_total'],
            ':actual_needs' => $snapshot[':actual_needs'],
            ':actual_wants' => $snapshot[':actual_wants'],
            ':actual_savings' => $snapshot[':actual_savings'],
            ':actual_total' => $snapshot[':actual_total'],
            ':surplus_amount' => $snapshot[':surplus_amount'],
            ':deficit_amount' => $snapshot[':deficit_amount'],
            ':spending_surplus_amount' => $snapshot[':spending_surplus_amount'],
            ':spending_deficit_amount' => $snapshot[':spending_deficit_amount'],
            ':calculation_hash' => $snapshot[':calculation_hash'],
            ':notes' => $snapshot[':notes'],
            ':closed_at' => $snapshot[':closed_at'],
            ':reopened_at' => $snapshot[':reopened_at'],
        ]);
    }

    public function updateCloseoutAuthoredFields(int $closeoutDbId, ?string $notes): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE monthly_closeouts
             SET notes = :notes, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $closeoutDbId,
            ':notes' => $notes,
        ]);
    }

    public function markCloseoutReopened(int $closeoutDbId, string $reopenedAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE monthly_closeouts
             SET status = :status,
                 reopened_at = :reopened_at,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $closeoutDbId,
            ':status' => 'reopened',
            ':reopened_at' => $reopenedAt,
        ]);
    }

    public function deleteAllocationsForCloseout(int $closeoutDbId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM monthly_closeout_allocations WHERE closeout_id = :closeout_id');
        $stmt->execute([':closeout_id' => $closeoutDbId]);
    }

    public function insertAllocations(int $closeoutDbId, int $userId, array $allocations): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO monthly_closeout_allocations (
               allocation_id,
               closeout_id,
               user_id,
               allocation_type,
               fund_id,
               label,
               amount,
               target_month,
               notes
             ) VALUES (
               :allocation_id,
               :closeout_id,
               :user_id,
               :allocation_type,
               :fund_id,
               :label,
               :amount,
               :target_month,
               :notes
             )'
        );

        foreach ($allocations as $allocation) {
            $stmt->execute([
                ':allocation_id' => $allocation['allocation_id'],
                ':closeout_id' => $closeoutDbId,
                ':user_id' => $userId,
                ':allocation_type' => $allocation['allocation_type'],
                ':fund_id' => $allocation['fund_id'],
                ':label' => $allocation['label'],
                ':amount' => $allocation['amount'],
                ':target_month' => $allocation['target_month'],
                ':notes' => $allocation['notes'],
            ]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function listAllocationsForCloseout(int $closeoutDbId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT mca.*,
                    f.fund_id AS fund_public_id,
                    f.name AS fund_name
             FROM monthly_closeout_allocations mca
             LEFT JOIN funds f ON f.id = mca.fund_id AND f.user_id = mca.user_id
             WHERE mca.closeout_id = :closeout_id
             ORDER BY mca.id ASC'
        );
        $stmt->execute([':closeout_id' => $closeoutDbId]);

        $rows = $stmt->fetchAll();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }
}
