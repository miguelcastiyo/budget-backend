<?php

declare(strict_types=1);

namespace App\Savings;

use App\Budget\BudgetSettingsResolver;
use App\Http\HttpException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class SavingsPlanService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BudgetSettingsResolver $budgetResolver
    ) {
    }

    /** @return array<string,mixed> */
    public function getForMonth(int $userId, string $month): array
    {
        $month = BudgetSettingsResolver::normalizeMonth($month);
        [$monthStart, $nextMonthStart] = $this->monthBounds($month);
        $budgetResolution = $this->budgetResolver->getEffectiveSettingsForMonth($userId, $month);
        $hasBudget = $budgetResolution['settings'] !== null;
        $savingsBudget = $hasBudget
            ? $this->cents($this->budgetResolver->resolveAmounts($budgetResolution['settings'])['savings'])
            : null;
        $closeout = $this->findCloseout($userId, $monthStart);
        $status = !$hasBudget ? 'missing_budget' : (($closeout['status'] ?? null) === 'closed' ? 'closed' : 'active');
        $allocations = $this->allocations($userId, $monthStart);
        $plannedByFund = [];
        foreach ($allocations as $row) {
            $plannedByFund[(int) $row['fund_id']] = $this->cents((string) $row['planned_amount']);
        }

        $savedAmount = $this->sumSavingsTransactions($userId, $monthStart, $nextMonthStart);
        $transactionContributions = $this->contributionsByFund($userId, $monthStart, $nextMonthStart);
        $closeoutContributions = $this->closeoutContributionsByFund($userId, $monthStart);
        $balances = $this->balancesByFund($userId);
        $fundRows = $this->fundRowsForResponse($userId, $plannedByFund, $transactionContributions, $closeoutContributions);

        $plannedToFunds = array_sum($plannedByFund);
        $transactionDirected = array_sum($transactionContributions);
        $closeoutDirected = array_sum($closeoutContributions);
        $overallocation = $savingsBudget === null ? 0 : max($plannedToFunds - $savingsBudget, 0);
        $summary = [
            'saved_amount' => $this->fmt($savedAmount),
            'remaining_to_save' => $this->fmt($savingsBudget === null ? 0 : max($savingsBudget - $savedAmount, 0)),
            'over_saved_amount' => $this->fmt($savingsBudget === null ? 0 : max($savedAmount - $savingsBudget, 0)),
            'planned_to_funds' => $this->fmt($plannedToFunds),
            'unassigned_budget' => $this->fmt($savingsBudget === null ? 0 : max($savingsBudget - $plannedToFunds, 0)),
            'transaction_directed_to_funds' => $this->fmt($transactionDirected),
            'saved_outside_funds' => $this->fmt(max($savedAmount - $transactionDirected, 0)),
            'closeout_directed_to_funds' => $this->fmt($closeoutDirected),
            'is_overallocated' => $overallocation > 0,
            'overallocation_amount' => $this->fmt($overallocation),
        ];

        $goalPacing = $this->goalPacing($month, $savingsBudget, $fundRows, $balances, $transactionContributions);

        return [
            'month' => $month,
            'status' => $status,
            'is_editable' => $status === 'active',
            'has_plan' => $allocations !== [],
            'budget' => [
                'has_budget' => $hasBudget,
                'resolved_effective_month' => $budgetResolution['resolved_effective_month'],
                'savings_budget' => $savingsBudget === null ? null : $this->fmt($savingsBudget),
            ],
            'summary' => $summary,
            'goal_pacing' => $goalPacing,
            'funds' => array_map(function (array $fund) use ($plannedByFund, $transactionContributions, $closeoutContributions, $balances, $month, $savingsBudget): array {
                $dbId = (int) $fund['_db_id'];
                $planned = $plannedByFund[$dbId] ?? 0;
                $transaction = $transactionContributions[$dbId] ?? 0;
                $closeout = $closeoutContributions[$dbId] ?? 0;
                $progress = $transaction + $closeout;
                return [
                    'fund' => [
                        'id' => (string) $fund['fund_id'],
                        'name' => (string) $fund['name'],
                        'status' => (string) $fund['status'],
                        'goal_amount' => $fund['goal_amount'] === null ? null : $this->fmt($this->cents((string) $fund['goal_amount'])),
                        'target_month' => $fund['target_month'] === null ? null : substr((string) $fund['target_month'], 0, 7),
                        'current_balance' => $this->fmt($balances[$dbId] ?? 0),
                    ],
                    'planned_amount' => $this->fmt($planned),
                    'transaction_contributed' => $this->fmt($transaction),
                    'closeout_contributed' => $this->fmt($closeout),
                    'progress_amount' => $this->fmt($progress),
                    'remaining_planned' => $this->fmt(max($planned - $progress, 0)),
                    'over_plan_amount' => $this->fmt(max($progress - $planned, 0)),
                    'pace' => $this->fundPace($fund, $month, $balances[$dbId] ?? 0, $transaction),
                ];
            }, $fundRows),
        ];
    }

    /** @return array<string,mixed> */
    public function getSummaryForMonth(int $userId, string $month): array
    {
        $full = $this->getForMonth($userId, $month);
        $attention = $full['summary']['is_overallocated'] === true;
        foreach ($full['funds'] as $item) {
            if (($item['fund']['status'] ?? '') === 'archived' && $this->cents((string) $item['planned_amount']) > 0) {
                $attention = true;
                break;
            }
        }

        return [
            'has_budget' => $full['budget']['has_budget'],
            'has_plan' => $full['has_plan'],
            'budget_amount' => $full['budget']['savings_budget'],
            'saved_amount' => $full['summary']['saved_amount'],
            'remaining_to_save' => $full['summary']['remaining_to_save'],
            'over_saved_amount' => $full['summary']['over_saved_amount'],
            'planned_to_funds' => $full['summary']['planned_to_funds'],
            'unassigned_budget' => $full['summary']['unassigned_budget'],
            'transaction_directed_to_funds' => $full['summary']['transaction_directed_to_funds'],
            'saved_outside_funds' => $full['summary']['saved_outside_funds'],
            'is_overallocated' => $full['summary']['is_overallocated'],
            'overallocation_amount' => $full['summary']['overallocation_amount'],
            'needs_attention' => $attention,
        ];
    }

    /** @param array<string,mixed> $payload */
    public function replaceForMonth(int $userId, string $month, array $payload): array
    {
        $month = BudgetSettingsResolver::normalizeMonth($month);
        $monthStart = $month . '-01';
        $budgetResolution = $this->budgetResolver->getEffectiveSettingsForMonth($userId, $month);
        if ($budgetResolution['settings'] === null) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'month', 'message' => 'Savings Plan requires budget settings for this month.'],
            ]);
        }
        $closeout = $this->findCloseout($userId, $monthStart);
        if (($closeout['status'] ?? null) === 'closed') {
            throw new HttpException(409, 'CONFLICT', 'Savings Plan is read-only while this month is closed. Reopen the month before editing the plan.');
        }
        $allocations = $payload['allocations'] ?? null;
        if (!is_array($allocations)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'allocations', 'message' => 'must be an array'],
            ]);
        }

        $budget = $this->cents($this->budgetResolver->resolveAmounts($budgetResolution['settings'])['savings']);
        $seen = [];
        $normalized = [];
        $plannedTotal = 0;
        foreach (array_values($allocations) as $index => $allocation) {
            if (!is_array($allocation)) {
                throw $this->allocationError($index, 'must be an object');
            }
            $fundId = trim((string) ($allocation['fund_id'] ?? ''));
            if ($fundId === '') {
                throw $this->allocationError($index, 'fund_id is required');
            }
            if (isset($seen[$fundId])) {
                throw $this->allocationError($index, 'duplicate fund_id');
            }
            $seen[$fundId] = true;
            $amount = $this->parsePositiveMoney($allocation['amount'] ?? null, 'amount', $index);
            $plannedTotal += $amount;
            $normalized[] = ['fund_id' => $fundId, 'amount' => $amount];
        }
        if ($plannedTotal > $budget) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Planned Fund allocations cannot exceed the month\'s Savings budget.', [
                ['field' => 'allocations', 'message' => 'planned allocations exceed the Savings budget by ' . $this->fmt($plannedTotal - $budget)],
            ]);
        }

        $funds = $this->resolveFunds($userId, array_column($normalized, 'fund_id'));
        foreach ($normalized as $index => $allocation) {
            $fund = $funds[$allocation['fund_id']] ?? null;
            if ($fund === null) {
                throw new HttpException(404, 'NOT_FOUND', 'Fund not found');
            }
            if ((string) $fund['status'] !== 'active') {
                throw $this->allocationError($index, 'archived funds cannot be included');
            }
        }

        try {
            $this->pdo->beginTransaction();
            $delete = $this->pdo->prepare('DELETE FROM monthly_savings_allocations WHERE user_id = :user_id AND month = :month');
            $delete->execute([':user_id' => $userId, ':month' => $monthStart]);
            if ($normalized !== []) {
                $insert = $this->pdo->prepare('INSERT INTO monthly_savings_allocations (user_id, month, fund_id, planned_amount) VALUES (:user_id, :month, :fund_id, :planned_amount)');
                foreach ($normalized as $allocation) {
                    $insert->execute([
                        ':user_id' => $userId,
                        ':month' => $monthStart,
                        ':fund_id' => $funds[$allocation['fund_id']]['id'],
                        ':planned_amount' => $this->fmt($allocation['amount']),
                    ]);
                }
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->getForMonth($userId, $month);
    }

    /** @return array<string,mixed>|null */
    private function findCloseout(int $userId, string $monthStart): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, status FROM monthly_closeouts WHERE user_id = :user_id AND month = :month LIMIT 1');
        $stmt->execute([':user_id' => $userId, ':month' => $monthStart]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private function allocations(int $userId, string $monthStart): array
    {
        $stmt = $this->pdo->prepare('SELECT fund_id, planned_amount FROM monthly_savings_allocations WHERE user_id = :user_id AND month = :month ORDER BY id ASC');
        $stmt->execute([':user_id' => $userId, ':month' => $monthStart]);
        return array_values(array_filter($stmt->fetchAll(), 'is_array'));
    }

    private function sumSavingsTransactions(int $userId, string $start, string $next): int
    {
        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS total FROM transactions WHERE user_id = :user_id AND category = 'savings' AND deleted_at IS NULL AND transaction_date >= :start AND transaction_date < :next");
        $stmt->execute([':user_id' => $userId, ':start' => $start, ':next' => $next]);
        return $this->cents((string) (($stmt->fetch()['total'] ?? '0')));
    }

    /** @return array<int,int> */
    private function contributionsByFund(int $userId, string $start, string $next): array
    {
        $stmt = $this->pdo->prepare("SELECT fe.fund_id, COALESCE(SUM(fe.amount), 0) AS total FROM fund_entries fe JOIN transactions t ON t.id = fe.source_transaction_id AND t.user_id = fe.user_id WHERE fe.user_id = :user_id AND fe.source_type = 'transaction' AND fe.entry_type = 'contribution' AND fe.direction = 'in' AND fe.deleted_at IS NULL AND fe.voided_at IS NULL AND t.category = 'savings' AND t.deleted_at IS NULL AND t.transaction_date >= :start AND t.transaction_date < :next GROUP BY fe.fund_id");
        $stmt->execute([':user_id' => $userId, ':start' => $start, ':next' => $next]);
        return $this->fundTotals($stmt->fetchAll());
    }

    /** @return array<int,int> */
    private function closeoutContributionsByFund(int $userId, string $monthStart): array
    {
        $stmt = $this->pdo->prepare("SELECT fe.fund_id, COALESCE(SUM(fe.amount), 0) AS total FROM fund_entries fe JOIN monthly_closeouts mc ON mc.id = fe.source_closeout_id AND mc.user_id = fe.user_id WHERE fe.user_id = :user_id AND fe.source_type = 'month_closeout' AND fe.entry_type = 'contribution' AND fe.direction = 'in' AND fe.deleted_at IS NULL AND fe.voided_at IS NULL AND mc.month = :month GROUP BY fe.fund_id");
        $stmt->execute([':user_id' => $userId, ':month' => $monthStart]);
        return $this->fundTotals($stmt->fetchAll());
    }

    /** @return array<int,int> */
    private function balancesByFund(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT fund_id, COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) AS balance FROM fund_entries WHERE user_id = :user_id AND deleted_at IS NULL AND voided_at IS NULL GROUP BY fund_id");
        $stmt->execute([':user_id' => $userId]);
        return $this->fundTotals($stmt->fetchAll());
    }

    /** @return array<int,array<string,mixed>> */
    private function fundRowsForResponse(int $userId, array $planned, array $transactions, array $closeouts): array
    {
        $referenced = array_fill_keys(array_map('intval', array_unique(array_merge(array_keys($planned), array_keys($transactions), array_keys($closeouts)))), true);
        $stmt = $this->pdo->prepare('SELECT * FROM funds WHERE user_id = :user_id ORDER BY sort_order ASC, LOWER(name) ASC, id ASC');
        $stmt->execute([':user_id' => $userId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) $row['id'];
            if ((string) $row['status'] !== 'active' && !isset($referenced[$id])) {
                continue;
            }
            $row['_db_id'] = $id;
            $rows[] = $row;
        }
        usort($rows, static function (array $a, array $b) use ($planned, $transactions, $closeouts): int {
            $aGroup = isset($planned[(int) $a['id']]) ? 0 : (isset($transactions[(int) $a['id']]) || isset($closeouts[(int) $a['id']]) ? 1 : 2);
            $bGroup = isset($planned[(int) $b['id']]) ? 0 : (isset($transactions[(int) $b['id']]) || isset($closeouts[(int) $b['id']]) ? 1 : 2);
            return [$aGroup, (int) $a['sort_order'], strtolower((string) $a['name']), (int) $a['id']] <=> [$bGroup, (int) $b['sort_order'], strtolower((string) $b['name']), (int) $b['id']];
        });
        return $rows;
    }

    /** @return array<string,array<string,mixed>> */
    private function resolveFunds(int $userId, array $publicIds): array
    {
        if ($publicIds === []) {
            return [];
        }
        $holders = [];
        $params = [':user_id' => $userId];
        foreach (array_values(array_unique($publicIds)) as $i => $id) {
            $key = ':fund_' . $i;
            $holders[] = $key;
            $params[$key] = $id;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM funds WHERE user_id = :user_id AND fund_id IN (' . implode(', ', $holders) . ')');
        $stmt->execute($params);
        $funds = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $funds[(string) $row['fund_id']] = $row;
            }
        }
        return $funds;
    }

    /** @param list<array<string,mixed>> $rows
     * @return array<int,int>
     */
    private function fundTotals(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $totals[(int) $row['fund_id']] = $this->cents((string) ($row['total'] ?? $row['balance'] ?? '0'));
            }
        }
        return $totals;
    }

    /** @param array<int,array<string,mixed>> $fundRows */
    private function goalPacing(string $month, ?int $budget, array $fundRows, array $balances, array $transactionContributions): array
    {
        if ($budget === null) {
            return ['status' => 'unavailable', 'recommended_total' => null, 'gap_to_savings_budget' => null, 'headroom_vs_savings_budget' => null];
        }
        $current = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m');
        if ($month !== $current) {
            return ['status' => 'historical', 'recommended_total' => null, 'gap_to_savings_budget' => null, 'headroom_vs_savings_budget' => null];
        }
        $total = 0;
        foreach ($fundRows as $fund) {
            $pace = $this->fundPace($fund, $month, $balances[(int) $fund['id']] ?? 0, $transactionContributions[(int) $fund['id']] ?? 0);
            if ($pace['recommended_amount'] !== null) {
                $total += $this->cents($pace['recommended_amount']);
            }
        }
        return ['status' => 'available', 'recommended_total' => $this->fmt($total), 'gap_to_savings_budget' => $this->fmt(max($total - $budget, 0)), 'headroom_vs_savings_budget' => $this->fmt(max($budget - $total, 0))];
    }

    /** @param array<string,mixed> $fund */
    private function fundPace(array $fund, string $month, int $balance, int $transactionContributed): array
    {
        if ((string) $fund['status'] !== 'active') {
            return ['status' => 'unavailable', 'planning_basis_balance' => null, 'goal_shortfall' => null, 'months_remaining' => null, 'recommended_amount' => null];
        }
        if ($fund['goal_amount'] === null) {
            return ['status' => 'no_goal', 'planning_basis_balance' => null, 'goal_shortfall' => null, 'months_remaining' => null, 'recommended_amount' => null];
        }
        if ($fund['target_month'] === null) {
            return ['status' => 'no_target', 'planning_basis_balance' => null, 'goal_shortfall' => null, 'months_remaining' => null, 'recommended_amount' => null];
        }
        $basis = $balance - $transactionContributed;
        $goal = $this->cents((string) $fund['goal_amount']);
        $shortfall = max($goal - $basis, 0);
        $target = substr((string) $fund['target_month'], 0, 7);
        if ($target < $month) {
            return ['status' => 'overdue', 'planning_basis_balance' => $this->fmt($basis), 'goal_shortfall' => $this->fmt($shortfall), 'months_remaining' => null, 'recommended_amount' => null];
        }
        $months = $this->monthDifference($month, $target) + 1;
        $recommended = $shortfall === 0 ? 0 : intdiv($shortfall + $months - 1, $months);
        return ['status' => $shortfall === 0 ? 'goal_met' : 'on_track_calculable', 'planning_basis_balance' => $this->fmt($basis), 'goal_shortfall' => $this->fmt($shortfall), 'months_remaining' => $months, 'recommended_amount' => $this->fmt($recommended)];
    }

    private function monthDifference(string $from, string $to): int
    {
        return (((int) substr($to, 0, 4) * 12) + (int) substr($to, 5, 2)) - (((int) substr($from, 0, 4) * 12) + (int) substr($from, 5, 2));
    }

    /** @return array{0:string,1:string} */
    private function monthBounds(string $month): array
    {
        $start = new DateTimeImmutable($month . '-01', new DateTimeZone('UTC'));
        return [$start->format('Y-m-d'), $start->modify('+1 month')->format('Y-m-d')];
    }

    private function parsePositiveMoney(mixed $value, string $field, int $index): int
    {
        if (!is_string($value) || !preg_match('/^\d+\.\d{2}$/', $value) || $this->cents($value) <= 0) {
            throw $this->allocationError($index, $field . ' must be a positive two-decimal amount');
        }
        return $this->cents($value);
    }

    private function allocationError(int $index, string $message): HttpException
    {
        return new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [['field' => 'allocations.' . $index, 'message' => $message]]);
    }

    private function cents(mixed $rawValue): int
    {
        $value = is_int($rawValue) || is_float($rawValue)
            ? number_format((float) $rawValue, 2, '.', '')
            : (string) $rawValue;
        $value = trim($value);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
        $result = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        return $negative ? -$result : $result;
    }

    private function fmt(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
