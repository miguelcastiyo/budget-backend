<?php

declare(strict_types=1);

namespace App\Overview;

use App\Budget\BudgetSettingsResolver;
use App\Http\HttpException;
use App\Savings\SavingsPlanService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class MonthOverviewService
{
    private const CATEGORY_ORDER = ['needs', 'wants', 'savings'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly BudgetSettingsResolver $budgetSettingsResolver,
        private readonly ?SavingsPlanService $savingsPlanService = null
    ) {
    }

    /** @return array<string,mixed> */
    public function getOverviewForMonth(int $userId, string $month): array
    {
        $month = BudgetSettingsResolver::normalizeMonth($month, 'month');
        [$dateFrom, $dateTo] = $this->monthDateRange($month);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $currentMonth = $now->format('Y-m');

        $budgetResolution = $this->budgetSettingsResolver->getEffectiveSettingsForMonth($userId, $month);
        $hasBudget = $budgetResolution['settings'] !== null;
        $budgetIncome = $hasBudget ? (float) $budgetResolution['settings']['monthly_income'] : null;
        $resolvedAmounts = $this->budgetSettingsResolver->resolveAmounts($budgetResolution['settings']);

        $summary = $this->queryMonthlySummary($userId, $dateFrom, $dateTo);
        $totalSpent = $summary['total_spent'];
        $totalBudget = $budgetIncome;
        $leftThisMonth = $hasBudget && $totalBudget !== null ? $totalBudget - $totalSpent : null;
        $percentSpent = $hasBudget && $totalBudget !== null
            ? ($totalBudget > 0.0 ? ($totalSpent / $totalBudget) * 100.0 : 0.0)
            : null;

        $actualByCategory = $this->queryCategoryTotals($userId, $dateFrom, $dateTo);
        $categories = $this->buildCategories($resolvedAmounts, $actualByCategory, $hasBudget);
        $tags = $this->queryTagSpend($userId, $dateFrom, $dateTo, $totalSpent);
        $recurring = $this->queryRecurringSummary($userId, $month);
        [, $recentTransactionsDateTo] = $this->recentTransactionsDateRange($month, $dateFrom, $dateTo, $currentMonth, $now);
        $recentTransactions = $this->queryRecentTransactions($userId, $dateFrom, $recentTransactionsDateTo, 5);
        $monthProgress = $this->monthProgress($month, $currentMonth, $now, $summary['total_spent'], $leftThisMonth, $hasBudget);
        $savingsPlan = $this->savingsPlanService?->getSummaryForMonth($userId, $month)
            ?? $this->emptySavingsPlanSummary();

        return [
            'month' => $month,
            'budget' => [
                'monthly_income' => $budgetIncome === null ? null : $this->fmt($budgetIncome),
                'resolved_effective_month' => $budgetResolution['resolved_effective_month'],
                'is_exact_match' => $budgetResolution['is_exact_match'],
                'has_budget' => $hasBudget,
            ],
            'summary' => [
                'total_spent' => $this->fmt($totalSpent),
                'total_budget' => $totalBudget === null ? null : $this->fmt($totalBudget),
                'left_this_month' => $leftThisMonth === null ? null : $this->fmt($leftThisMonth),
                'percent_spent' => $percentSpent === null ? null : $this->fmt($percentSpent),
            ],
            'month_progress' => $monthProgress,
            'categories' => $categories,
            'tags' => $tags,
            'recurring' => $recurring,
            'savings_plan' => $savingsPlan,
            'recent_transactions' => $recentTransactions,
            'status_cards' => $this->statusCards(
                $month,
                $budgetResolution,
                $summary['total_spent'],
                $monthProgress,
                $categories,
                $recurring
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function emptySavingsPlanSummary(): array
    {
        return [
            'has_budget' => false,
            'has_plan' => false,
            'budget_amount' => null,
            'saved_amount' => '0.00',
            'remaining_to_save' => '0.00',
            'over_saved_amount' => '0.00',
            'planned_to_funds' => '0.00',
            'unassigned_budget' => '0.00',
            'transaction_directed_to_funds' => '0.00',
            'saved_outside_funds' => '0.00',
            'is_overallocated' => false,
            'overallocation_amount' => '0.00',
            'needs_attention' => false,
        ];
    }

    /** @return array{0:string,1:string} */
    private function monthDateRange(string $month): array
    {
        $start = DateTimeImmutable::createFromFormat('Y-m-d', $month . '-01', new DateTimeZone('UTC'));
        if (!$start) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'month', 'message' => 'must be a valid month'],
            ]);
        }

        return [$start->format('Y-m-d'), $start->modify('last day of this month')->format('Y-m-d')];
    }

    /** @return array{0:string,1:string} */
    private function recentTransactionsDateRange(
        string $month,
        string $dateFrom,
        string $dateTo,
        string $currentMonth,
        DateTimeImmutable $now
    ): array {
        if ($month !== $currentMonth) {
            return [$dateFrom, $dateTo];
        }

        return [$dateFrom, min($dateTo, $now->format('Y-m-d'))];
    }

    /** @return array<string,float> */
    private function queryMonthlySummary(int $userId, string $dateFrom, string $dateTo): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
               COALESCE(SUM(amount), 0) AS total_spent,
               COUNT(*) AS transaction_count
             FROM transactions
             WHERE user_id = :user_id
               AND deleted_at IS NULL
               AND transaction_date BETWEEN :date_from AND :date_to'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':date_from' => $dateFrom,
            ':date_to' => $dateTo,
        ]);

        $row = $stmt->fetch() ?: [];

        return [
            'total_spent' => (float) ($row['total_spent'] ?? 0.0),
            'transaction_count' => (int) ($row['transaction_count'] ?? 0),
        ];
    }

    /** @return array<string,float> */
    private function queryCategoryTotals(int $userId, string $dateFrom, string $dateTo): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT category, COALESCE(SUM(amount), 0) AS actual_spend
             FROM transactions
             WHERE user_id = :user_id
               AND deleted_at IS NULL
               AND transaction_date BETWEEN :date_from AND :date_to
             GROUP BY category'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':date_from' => $dateFrom,
            ':date_to' => $dateTo,
        ]);

        $actualByCategory = [];
        foreach ($stmt->fetchAll() as $row) {
            $actualByCategory[(string) $row['category']] = (float) $row['actual_spend'];
        }

        return $actualByCategory;
    }

    /**
     * @param array{needs:float,wants:float,savings:float} $resolvedAmounts
     * @return list<array{category:string,budget_amount:string,actual_spend:string,remaining_amount:string,percent_used:string,status:'under'|'near'|'over'}>
     */
    private function buildCategories(array $resolvedAmounts, array $actualByCategory, bool $hasBudget): array
    {
        $items = [];
        foreach (self::CATEGORY_ORDER as $category) {
            $budgetAmount = $hasBudget ? (float) ($resolvedAmounts[$category] ?? 0.0) : 0.0;
            $actualSpend = (float) ($actualByCategory[$category] ?? 0.0);
            $remaining = $budgetAmount - $actualSpend;
            $percentUsed = $budgetAmount > 0.0 ? ($actualSpend / $budgetAmount) * 100.0 : 0.0;

            $items[] = [
                'category' => $category,
                'budget_amount' => $this->fmt($budgetAmount),
                'actual_spend' => $this->fmt($actualSpend),
                'remaining_amount' => $this->fmt($remaining),
                'percent_used' => $this->fmt($percentUsed),
                'status' => $budgetAmount > 0.0 && $percentUsed > 100.0
                    ? 'over'
                    : ($budgetAmount > 0.0 && $percentUsed >= 80.0 ? 'near' : 'under'),
            ];
        }

        return $items;
    }

    /**
     * @return list<array{tag_id:string,tag_name:string,icon_key:?string,spend:string,percent_of_monthly_spend:string}>
     */
    private function queryTagSpend(int $userId, string $dateFrom, string $dateTo, float $monthTotalSpent): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
               t.tag_id,
               tg.name AS tag_name,
               tg.icon_key AS tag_icon_key,
               SUM(t.amount) AS spend
             FROM transactions t
             JOIN tags tg ON tg.id = t.tag_id AND tg.user_id = t.user_id
             WHERE t.user_id = :user_id
               AND t.deleted_at IS NULL
               AND t.transaction_date BETWEEN :date_from AND :date_to
             GROUP BY t.tag_id, tg.name, tg.icon_key
             HAVING SUM(t.amount) > 0
             ORDER BY spend DESC, tg.name ASC'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':date_from' => $dateFrom,
            ':date_to' => $dateTo,
        ]);

        $rows = $stmt->fetchAll();
        $items = [];
        foreach ($rows as $row) {
            $spend = (float) $row['spend'];
            $items[] = [
                'tag_id' => (string) $row['tag_id'],
                'tag_name' => (string) $row['tag_name'],
                'icon_key' => $row['tag_icon_key'] === null ? null : (string) $row['tag_icon_key'],
                'spend' => $this->fmt($spend),
                'percent_of_monthly_spend' => $this->fmt($monthTotalSpent > 0.0 ? ($spend / $monthTotalSpent) * 100.0 : 0.0),
            ];
        }

        return $items;
    }

    /**
     * @return array{committed_total:string,generated_total:string,upcoming_total:string,items_count:int,generated_count:int,upcoming_count:int}
     */
    private function queryRecurringSummary(int $userId, string $month): array
    {
        $monthStart = $month . '-01';
        $monthEnd = DateTimeImmutable::createFromFormat('Y-m-d', $monthStart, new DateTimeZone('UTC'));
        if (!$monthEnd) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'month', 'message' => 'must be a valid month'],
            ]);
        }
        $monthEnd = $monthEnd->modify('last day of this month')->format('Y-m-d');

        $stmt = $this->pdo->prepare(
            'SELECT
               re.id,
               re.amount,
               EXISTS(
                 SELECT 1
                 FROM recurring_expense_occurrences reo
                 WHERE reo.user_id = re.user_id
                   AND reo.recurring_expense_id = re.id
                   AND reo.occurrence_month = :instance_month
                   AND reo.transaction_id IS NOT NULL
                 LIMIT 1
               ) AS generated_for_month
             FROM recurring_expenses re
             WHERE re.user_id = :user_id
               AND re.is_active = 1
               AND re.deleted_at IS NULL
               AND re.starts_month <= :month_end
               AND (re.ends_month IS NULL OR re.ends_month >= :month_start)
             ORDER BY re.id ASC'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':instance_month' => $monthStart,
            ':month_start' => $monthStart,
            ':month_end' => $monthEnd,
        ]);

        $committedTotal = 0.0;
        $generatedTotal = 0.0;
        $generatedCount = 0;
        $itemsCount = 0;
        foreach ($stmt->fetchAll() as $row) {
            $itemsCount++;
            $amount = (float) $row['amount'];
            $committedTotal += $amount;
            if ((int) $row['generated_for_month'] === 1) {
                $generatedCount++;
                $generatedTotal += $amount;
            }
        }

        $upcomingCount = $itemsCount - $generatedCount;

        return [
            'committed_total' => $this->fmt($committedTotal),
            'generated_total' => $this->fmt($generatedTotal),
            'upcoming_total' => $this->fmt($committedTotal - $generatedTotal),
            'items_count' => $itemsCount,
            'generated_count' => $generatedCount,
            'upcoming_count' => $upcomingCount,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function queryRecentTransactions(int $userId, string $dateFrom, string $dateTo, int $limit): array
    {
        $sql = <<<'SQL'
SELECT
  t.id,
  t.transaction_date,
  t.expense,
  t.amount,
  t.category,
  t.is_split,
  t.notes,
  t.source,
  tg.id AS tag_id,
  tg.name AS tag_name,
  tg.icon_key AS tag_icon_key,
  c.id AS card_id,
  c.name AS card_name,
  c.is_favorite AS card_is_favorite,
  cx.id AS context_id,
  cx.name AS context_name,
  cx.icon_key AS context_icon_key,
  reo.recurring_expense_id,
  t.created_at,
  t.updated_at
FROM transactions t
JOIN tags tg ON tg.id = t.tag_id AND tg.user_id = t.user_id
LEFT JOIN cards c ON c.id = t.card_id AND c.user_id = t.user_id
LEFT JOIN contexts cx ON cx.id = t.context_id AND cx.user_id = t.user_id
LEFT JOIN (
  SELECT user_id, transaction_id, MIN(recurring_expense_id) AS recurring_expense_id
  FROM recurring_expense_occurrences
  WHERE transaction_id IS NOT NULL
  GROUP BY user_id, transaction_id
) reo ON reo.transaction_id = t.id AND reo.user_id = t.user_id
WHERE t.user_id = :user_id
  AND t.deleted_at IS NULL
  AND t.transaction_date BETWEEN :date_from AND :date_to
ORDER BY t.transaction_date DESC, t.created_at DESC, t.id DESC
LIMIT :limit
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':date_from', $dateFrom);
        $stmt->bindValue(':date_to', $dateTo);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id' => (string) $row['id'],
                'date' => (string) $row['transaction_date'],
                'expense' => (string) $row['expense'],
                'amount' => $this->fmt((float) $row['amount']),
                'category' => (string) $row['category'],
                'is_split' => ((int) $row['is_split']) === 1,
                'notes' => $row['notes'] === null ? null : (string) $row['notes'],
                'source' => (string) $row['source'],
                'recurring_expense_id' => $row['recurring_expense_id'] === null ? null : (string) $row['recurring_expense_id'],
                'tag' => [
                    'id' => (string) $row['tag_id'],
                    'name' => (string) $row['tag_name'],
                    'icon_key' => $row['tag_icon_key'] === null ? null : (string) $row['tag_icon_key'],
                ],
                'card' => $row['card_id'] === null
                    ? null
                    : [
                        'id' => (string) $row['card_id'],
                        'name' => (string) $row['card_name'],
                        'is_favorite' => ((int) ($row['card_is_favorite'] ?? 0)) === 1,
                    ],
                'context' => $row['context_id'] === null
                    ? null
                    : [
                        'id' => (string) $row['context_id'],
                        'name' => (string) $row['context_name'],
                        'icon_key' => $row['context_icon_key'] === null ? null : (string) $row['context_icon_key'],
                    ],
                'created_at' => $this->formatTimestamp((string) $row['created_at']),
                'updated_at' => $this->formatTimestamp((string) $row['updated_at']),
            ];
        }

        return $items;
    }

    /**
     * @param array{requested_month:string,resolved_effective_month:?string,is_exact_match:bool,settings:?array<string,mixed>} $budgetResolution
     * @param array{total_spent:float,transaction_count:int} $summary
     * @param array{status:string,days_in_month:int,day_of_month:int,days_elapsed:int,days_remaining:int,percent_elapsed:string,daily_available_remaining:?string,projected_month_end_spend:?string} $progress
     * @param list<array{category:string,budget_amount:string,actual_spend:string,remaining_amount:string,percent_used:string,status:'under'|'near'|'over'}> $categories
     * @param array{committed_total:string,generated_total:string,upcoming_total:string,items_count:int,generated_count:int,upcoming_count:int} $recurring
     * @return list<array{id:string,tone:'good'|'neutral'|'warning'|'danger',title:string,value:string,detail:string}>
     */
    private function statusCards(
        string $month,
        array $budgetResolution,
        float $totalSpent,
        array $progress,
        array $categories,
        array $recurring
    ): array {
        $cards = [];
        $hasBudget = (bool) $budgetResolution['settings'];

        if (!$hasBudget) {
            $cards[] = [
                'id' => 'no_budget',
                'tone' => 'neutral',
                'title' => 'No budget set',
                'value' => 'No budget set',
                'detail' => 'Create a budget to compare spending against monthly targets.',
            ];
        }

        if ($hasBudget && $progress['status'] === 'current' && $budgetResolution['resolved_effective_month'] !== null) {
            $percentSpent = $budgetResolution['settings'] !== null ? $this->summaryPercentSpent($budgetResolution['settings'], $totalSpent) : null;
            $percentElapsed = $this->parsePercent($progress['percent_elapsed']);
            $diff = $percentSpent - $percentElapsed;

            $tone = match (true) {
                $diff <= 5.0 => 'good',
                $diff <= 15.0 => 'neutral',
                $diff <= 30.0 => 'warning',
                default => 'danger',
            };
            $title = match ($tone) {
                'good' => 'On pace',
                'neutral' => 'Slightly ahead',
                'warning' => 'Behind pace',
                default => 'Off pace',
            };

            $cards[] = [
                'id' => 'month_pace',
                'tone' => $tone,
                'title' => $title,
                'value' => $this->fmt($percentSpent) . '% spent',
                'detail' => $progress['percent_elapsed'] . '% through the month.',
            ];
        }

        $largestCategory = $this->largestCategory($categories);
        if ($largestCategory !== null) {
            $cards[] = [
                'id' => 'largest_category',
                'tone' => $largestCategory['status'] === 'over'
                    ? 'warning'
                    : ($largestCategory['status'] === 'near' ? 'neutral' : 'good'),
                'title' => 'Largest category',
                'value' => ucfirst($largestCategory['category']) . ' · ' . $largestCategory['actual_spend'],
                'detail' => 'Highest spending category this month.',
            ];
        }

        $cards[] = [
            'id' => 'recurring',
            'tone' => 'neutral',
            'title' => 'Recurring',
            'value' => $recurring['committed_total'],
            'detail' => $recurring['upcoming_total'] . ' upcoming this month.',
        ];

        if ($hasBudget) {
            $resolvedMonth = $budgetResolution['resolved_effective_month'];
            $sourceValue = $budgetResolution['is_exact_match']
                ? 'Exact budget'
                : ('Using budget from ' . $this->monthLabel($resolvedMonth ?? $month));

            $cards[] = [
                'id' => 'budget_source',
                'tone' => $budgetResolution['is_exact_match'] ? 'good' : 'neutral',
                'title' => $budgetResolution['is_exact_match'] ? 'Exact budget' : 'Inherited budget',
                'value' => $sourceValue,
                'detail' => 'Budget applies to ' . $this->monthLabel($month) . '.',
            ];
        }

        return array_slice($cards, 0, 5);
    }

    /**
     * @param list<array{category:string,budget_amount:string,actual_spend:string,remaining_amount:string,percent_used:string,status:'under'|'near'|'over'}> $categories
     * @return array{category:string,budget_amount:string,actual_spend:string,remaining_amount:string,percent_used:string,status:'under'|'near'|'over'}|null
     */
    private function largestCategory(array $categories): ?array
    {
        $largest = null;
        foreach ($categories as $category) {
            if ($largest === null || (float) $category['actual_spend'] > (float) $largest['actual_spend']) {
                $largest = $category;
            }
        }

        return $largest;
    }

    /**
     * @param array{total_spent:float,transaction_count:int} $summary
     * @return array{status:'past'|'current'|'future',days_in_month:int,day_of_month:int,days_elapsed:int,days_remaining:int,percent_elapsed:string,daily_available_remaining:?string,projected_month_end_spend:?string}
     */
    private function monthProgress(
        string $month,
        string $currentMonth,
        DateTimeImmutable $now,
        float $totalSpent,
        ?float $leftThisMonth,
        bool $hasBudget
    ): array {
        $monthStart = DateTimeImmutable::createFromFormat('Y-m-d', $month . '-01', new DateTimeZone('UTC'));
        if (!$monthStart) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'month', 'message' => 'must be a valid month'],
            ]);
        }

        $daysInMonth = (int) $monthStart->modify('last day of this month')->format('d');
        $status = $month < $currentMonth ? 'past' : ($month > $currentMonth ? 'future' : 'current');
        $daysElapsed = match ($status) {
            'past' => $daysInMonth,
            'future' => 0,
            default => (int) $now->format('j'),
        };
        $dayOfMonth = match ($status) {
            'past' => $daysInMonth,
            'future' => 0,
            default => (int) $now->format('j'),
        };
        $daysRemaining = $daysInMonth - $daysElapsed;
        $percentElapsed = $daysInMonth > 0 ? ($daysElapsed / $daysInMonth) * 100.0 : 0.0;
        $dailyAvailableRemaining = $status === 'current' && $hasBudget && $leftThisMonth !== null
            ? ($leftThisMonth / max($daysRemaining, 1))
            : null;
        $projectedMonthEndSpend = $status === 'current' && $percentElapsed > 0.0
            ? ($totalSpent / ($percentElapsed / 100.0))
            : null;

        return [
            'status' => $status,
            'days_in_month' => $daysInMonth,
            'day_of_month' => $dayOfMonth,
            'days_elapsed' => $daysElapsed,
            'days_remaining' => $daysRemaining,
            'percent_elapsed' => $this->fmt($percentElapsed),
            'daily_available_remaining' => $dailyAvailableRemaining === null ? null : $this->fmt($dailyAvailableRemaining),
            'projected_month_end_spend' => $projectedMonthEndSpend === null ? null : $this->fmt($projectedMonthEndSpend),
        ];
    }

    private function summaryPercentSpent(array $settings, float $totalSpent): ?float
    {
        $income = (float) $settings['monthly_income'];
        if ($income <= 0.0) {
            return 0.0;
        }

        return ($totalSpent / $income) * 100.0;
    }

    private function parsePercent(mixed $value): float
    {
        return $value === null ? 0.0 : (float) $value;
    }

    private function monthLabel(string $month): string
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m', $month, new DateTimeZone('UTC'));
        if (!$dt) {
            return $month;
        }

        return $dt->format('F Y');
    }

    private function fmt(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function formatTimestamp(string $value): string
    {
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));

        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
