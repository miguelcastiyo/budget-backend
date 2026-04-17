<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Recurring\RecurringExpenseService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class MetricsController
{
    private const CATEGORY_ORDER = ['needs', 'wants', 'savings_debts'];
    private const WEEKDAY_ORDER = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthService $auth,
        private readonly RecurringExpenseService $recurring
    ) {
    }

    public function tags(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        [$month, $startDate, $endDate] = $this->monthRangeFromQuery($request);
        $this->recurring->ensureGeneratedForMonth($ctx->userId(), $month);

        return Response::json($this->buildMonthlyTagMetrics($ctx->userId(), $month, $startDate, $endDate));
    }

    public function categories(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        [$month, $startDate, $endDate] = $this->monthRangeFromQuery($request);
        $this->recurring->ensureGeneratedForMonth($ctx->userId(), $month);

        return Response::json($this->buildMonthlyCategoryMetrics($ctx->userId(), $month, $startDate, $endDate));
    }

    public function dashboard(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        [$month, $startDate, $endDate] = $this->monthRangeFromQuery($request);
        $this->recurring->ensureGeneratedForMonth($ctx->userId(), $month);

        return Response::json([
            'month' => $month,
            'category_metrics' => $this->buildMonthlyCategoryMetrics($ctx->userId(), $month, $startDate, $endDate),
            'tag_metrics' => $this->buildMonthlyTagMetrics($ctx->userId(), $month, $startDate, $endDate),
            'recent_transactions' => $this->queryRecentTransactionsForMonth($ctx->userId(), $startDate, $endDate),
        ]);
    }

    public function insights(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        [$dateFrom, $dateTo, $startDate, $endDate] = $this->insightsDateRangeFromQuery($request);
        $this->recurring->ensureGeneratedForDateRange($ctx->userId(), $dateFrom, $dateTo);

        $monthlySpendByMonth = $this->queryMonthlySpendTrend($ctx->userId(), $dateFrom, $dateTo);
        $monthlySpendTrend = $this->expandMonthlyTrend($startDate, $endDate, $monthlySpendByMonth);

        $actualByCategory = $this->queryCategoryTotals($ctx->userId(), $dateFrom, $dateTo);
        $totalSpend = 0.0;
        foreach (self::CATEGORY_ORDER as $category) {
            $totalSpend += (float) ($actualByCategory[$category] ?? 0.0);
        }

        $categoryBreakdown = [];
        foreach (self::CATEGORY_ORDER as $category) {
            $spend = (float) ($actualByCategory[$category] ?? 0.0);
            $percent = $totalSpend > 0.0 ? ($spend / $totalSpend) * 100.0 : 0.0;
            $categoryBreakdown[] = [
                'category' => $category,
                'spend' => $this->fmt($spend),
                'percent_of_total_spend' => $this->fmt($percent),
            ];
        }

        $settings = $this->loadBudgetSettings($ctx->userId());
        $plan = $this->budgetPlanFromSettings($settings);
        $monthsInRange = $this->countMonthsInRange($startDate, $endDate);
        $categoryBudgetVsActual = [];
        foreach (self::CATEGORY_ORDER as $category) {
            $monthlyBudget = (float) ($plan['budget_amounts'][$category] ?? 0.0);
            $budgetAmount = $monthlyBudget * $monthsInRange;
            $actualSpend = (float) ($actualByCategory[$category] ?? 0.0);
            $percentUsed = $budgetAmount > 0.0 ? ($actualSpend / $budgetAmount) * 100.0 : 0.0;

            $categoryBudgetVsActual[] = [
                'category' => $category,
                'budget_amount' => $this->fmt($budgetAmount),
                'actual_spend' => $this->fmt($actualSpend),
                'percent_used' => $this->fmt($percentUsed),
            ];
        }

        $tagBreakdown = $this->queryTagBreakdown($ctx->userId(), $dateFrom, $dateTo, $totalSpend);
        $dayOfWeekSpend = $this->queryDayOfWeekSpend($ctx->userId(), $dateFrom, $dateTo);
        $largestTransactions = $this->queryLargestTransactions($ctx->userId(), $dateFrom, $dateTo);
        $totalTransactions = $this->countTransactions($ctx->userId(), $dateFrom, $dateTo);

        $recurringSpend = $this->queryRecurringSpend($ctx->userId(), $dateFrom, $dateTo);
        $variableSpend = max($totalSpend - $recurringSpend, 0.0);

        $recurringPercent = $totalSpend > 0.0 ? ($recurringSpend / $totalSpend) * 100.0 : 0.0;
        $variablePercent = $totalSpend > 0.0 ? ($variableSpend / $totalSpend) * 100.0 : 0.0;

        return Response::json([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'months_in_range' => $monthsInRange,
            'total_spend' => $this->fmt($totalSpend),
            'total_transactions' => $totalTransactions,
            'monthly_spend_trend' => $monthlySpendTrend,
            'category_breakdown' => $categoryBreakdown,
            'category_budget_vs_actual' => $categoryBudgetVsActual,
            'tag_breakdown' => $tagBreakdown,
            'day_of_week_spend' => $dayOfWeekSpend,
            'largest_transactions' => $largestTransactions,
            'recurring_vs_variable' => [
                'recurring' => $this->fmt($recurringSpend),
                'variable' => $this->fmt($variableSpend),
                'recurring_percent' => $this->fmt($recurringPercent),
                'variable_percent' => $this->fmt($variablePercent),
            ],
        ]);
    }

    /** @return array{0:string,1:string,2:string} */
    private function monthRangeFromQuery(Request $request): array
    {
        $month = trim((string) ($request->query['month'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'month', 'message' => 'must be YYYY-MM'],
            ]);
        }

        $dt = DateTimeImmutable::createFromFormat('Y-m', $month, new DateTimeZone('UTC'));
        if (!$dt || $dt->format('Y-m') !== $month) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'month', 'message' => 'must be a valid month'],
            ]);
        }

        $start = $dt->setDate((int) $dt->format('Y'), (int) $dt->format('m'), 1);
        $end = $start->modify('last day of this month');

        return [$month, $start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    /** @return array{0:string,1:string,2:DateTimeImmutable,3:DateTimeImmutable} */
    private function insightsDateRangeFromQuery(Request $request): array
    {
        $dateFromRaw = trim((string) ($request->query['date_from'] ?? ''));
        $dateToRaw = trim((string) ($request->query['date_to'] ?? ''));

        if ($dateFromRaw === '' || $dateToRaw === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'date_range', 'message' => 'date_from and date_to are both required'],
            ]);
        }

        $dateFrom = $this->validatedDate($dateFromRaw, 'date_from');
        $dateTo = $this->validatedDate($dateToRaw, 'date_to');

        if ($dateFrom > $dateTo) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'date_range', 'message' => 'date_from must be <= date_to'],
            ]);
        }

        $startDate = DateTimeImmutable::createFromFormat('Y-m-d', $dateFrom, new DateTimeZone('UTC'));
        $endDate = DateTimeImmutable::createFromFormat('Y-m-d', $dateTo, new DateTimeZone('UTC'));
        if (!$startDate || !$endDate) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'date_range', 'message' => 'must be valid dates'],
            ]);
        }

        return [$dateFrom, $dateTo, $startDate, $endDate];
    }

    private function validatedDate(string $value, string $field): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be YYYY-MM-DD'],
            ]);
        }

        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value, new DateTimeZone('UTC'));
        if (!$dt || $dt->format('Y-m-d') !== $value) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a valid date'],
            ]);
        }

        return $value;
    }

    private function buildMonthlyCategoryMetrics(int $userId, string $month, string $startDate, string $endDate): array
    {
        $settings = $this->loadBudgetSettings($userId);
        $plan = $this->budgetPlanFromSettings($settings);
        $actualByCategory = $this->queryCategoryTotals($userId, $startDate, $endDate);

        $categories = [];
        foreach (self::CATEGORY_ORDER as $category) {
            $budgetAmount = (float) ($plan['budget_amounts'][$category] ?? 0.0);
            $actualSpend = (float) ($actualByCategory[$category] ?? 0.0);
            $percentUsed = $budgetAmount > 0.0 ? ($actualSpend / $budgetAmount) * 100.0 : 0.0;

            $categories[] = [
                'category' => $category,
                'budget_amount' => $this->fmt($budgetAmount),
                'actual_spend' => $this->fmt($actualSpend),
                'percent_used' => $this->fmt($percentUsed),
            ];
        }

        return [
            'month' => $month,
            'monthly_income' => $this->fmt((float) $plan['monthly_income']),
            'categories' => $categories,
        ];
    }

    private function buildMonthlyTagMetrics(int $userId, string $month, string $startDate, string $endDate): array
    {
        $rows = $this->queryMonthlyTagRows($userId, $startDate, $endDate);
        $totalSpend = 0.0;
        foreach ($rows as $row) {
            $totalSpend += (float) $row['spend'];
        }

        $items = [];
        foreach ($rows as $row) {
            $spend = (float) $row['spend'];
            $percent = $totalSpend > 0.0 ? ($spend / $totalSpend) * 100.0 : 0.0;

            $items[] = [
                'tag_id' => (string) $row['tag_id'],
                'tag_name' => (string) $row['tag_name'],
                'icon_key' => $row['tag_icon_key'] === null ? null : (string) $row['tag_icon_key'],
                'spend' => $this->fmt($spend),
                'percent_of_monthly_spend' => $this->fmt($percent),
            ];
        }

        return [
            'month' => $month,
            'total_spend' => $this->fmt($totalSpend),
            'tags' => $items,
        ];
    }

    /** @return array<string,float> */
    private function queryMonthlySpendTrend(int $userId, string $dateFrom, string $dateTo): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS month, SUM(amount) AS total_spend
             FROM transactions
             WHERE user_id = :user_id
               AND deleted_at IS NULL
               AND transaction_date BETWEEN :date_from AND :date_to
             GROUP BY month
             ORDER BY month ASC"
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':date_from' => $dateFrom,
            ':date_to' => $dateTo,
        ]);

        $totals = [];
        foreach ($stmt->fetchAll() as $row) {
            $totals[(string) $row['month']] = (float) $row['total_spend'];
        }

        return $totals;
    }

    /**
     * @param array<string,float> $totalsByMonth
     * @return list<array{month:string,total_spend:string}>
     */
    private function expandMonthlyTrend(DateTimeImmutable $startDate, DateTimeImmutable $endDate, array $totalsByMonth): array
    {
        $items = [];
        $cursor = $startDate->modify('first day of this month');
        $lastMonth = $endDate->modify('first day of this month');

        while ($cursor <= $lastMonth) {
            $month = $cursor->format('Y-m');
            $items[] = [
                'month' => $month,
                'total_spend' => $this->fmt((float) ($totalsByMonth[$month] ?? 0.0)),
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return $items;
    }

    /** @return array<string,float> */
    private function queryCategoryTotals(int $userId, string $dateFrom, string $dateTo): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT category, SUM(amount) AS actual_spend
             FROM transactions
             WHERE user_id = :user_id
               AND deleted_at IS NULL
               AND transaction_date BETWEEN :date_from AND :date_to
             GROUP BY category"
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

    /** @return list<array<string,mixed>> */
    private function queryMonthlyTagRows(int $userId, string $dateFrom, string $dateTo): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
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
             ORDER BY spend DESC, tg.name ASC"
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':date_from' => $dateFrom,
            ':date_to' => $dateTo,
        ]);

        return $stmt->fetchAll();
    }

    /** @return list<array{tag_id:string,tag_name:string,icon_key:?string,spend:string,percent_of_total_spend:string}> */
    private function queryTagBreakdown(int $userId, string $dateFrom, string $dateTo, float $totalSpend): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
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
             ORDER BY spend DESC, tg.name ASC"
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':date_from' => $dateFrom,
            ':date_to' => $dateTo,
        ]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $spend = (float) $row['spend'];
            $items[] = [
                'tag_id' => (string) $row['tag_id'],
                'tag_name' => (string) $row['tag_name'],
                'icon_key' => $row['tag_icon_key'] === null ? null : (string) $row['tag_icon_key'],
                'spend' => $this->fmt($spend),
                'percent_of_total_spend' => $this->fmt($totalSpend > 0.0 ? ($spend / $totalSpend) * 100.0 : 0.0),
            ];
        }

        return $items;
    }

    /** @return list<array{day:string,avg_spend:string,total_spend:string,transactions_count:int}> */
    private function queryDayOfWeekSpend(int $userId, string $dateFrom, string $dateTo): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
               WEEKDAY(transaction_date) AS weekday_index,
               AVG(amount) AS avg_spend,
               SUM(amount) AS total_spend,
               COUNT(*) AS transactions_count
             FROM transactions
             WHERE user_id = :user_id
               AND deleted_at IS NULL
               AND transaction_date BETWEEN :date_from AND :date_to
             GROUP BY weekday_index
             ORDER BY weekday_index ASC"
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':date_from' => $dateFrom,
            ':date_to' => $dateTo,
        ]);

        $byIndex = [];
        foreach ($stmt->fetchAll() as $row) {
            $index = (int) $row['weekday_index'];
            if ($index < 0 || $index > 6) {
                continue;
            }
            $byIndex[$index] = [
                'avg_spend' => (float) $row['avg_spend'],
                'total_spend' => (float) $row['total_spend'],
                'transactions_count' => (int) $row['transactions_count'],
            ];
        }

        $items = [];
        foreach (self::WEEKDAY_ORDER as $index => $day) {
            $metrics = $byIndex[$index] ?? [
                'avg_spend' => 0.0,
                'total_spend' => 0.0,
                'transactions_count' => 0,
            ];

            $items[] = [
                'day' => $day,
                'avg_spend' => $this->fmt((float) $metrics['avg_spend']),
                'total_spend' => $this->fmt((float) $metrics['total_spend']),
                'transactions_count' => (int) $metrics['transactions_count'],
            ];
        }

        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function queryLargestTransactions(int $userId, string $dateFrom, string $dateTo): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
               t.id,
               t.transaction_date,
               t.expense,
               t.amount,
               t.category,
               t.is_split,
               tg.id AS tag_id,
               tg.name AS tag_name,
               tg.icon_key AS tag_icon_key,
               c.name AS card_name
             FROM transactions t
             JOIN tags tg ON tg.id = t.tag_id AND tg.user_id = t.user_id
             LEFT JOIN cards c ON c.id = t.card_id AND c.user_id = t.user_id
             WHERE t.user_id = :user_id
               AND t.deleted_at IS NULL
               AND t.transaction_date BETWEEN :date_from AND :date_to
             ORDER BY t.amount DESC, t.transaction_date DESC, t.id DESC
             LIMIT 8"
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':date_from' => $dateFrom,
            ':date_to' => $dateTo,
        ]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'transaction_id' => (string) $row['id'],
                'date' => (string) $row['transaction_date'],
                'expense' => (string) $row['expense'],
                'amount' => $this->fmt((float) $row['amount']),
                'category' => (string) $row['category'],
                'is_split' => ((int) $row['is_split']) === 1,
                'tag' => [
                    'id' => (string) $row['tag_id'],
                    'name' => (string) $row['tag_name'],
                    'icon_key' => $row['tag_icon_key'] === null ? null : (string) $row['tag_icon_key'],
                ],
                'card_name' => $row['card_name'] === null ? null : (string) $row['card_name'],
            ];
        }

        return $items;
    }

    private function queryRecurringSpend(int $userId, string $dateFrom, string $dateTo): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(t.amount), 0) AS recurring_spend
             FROM recurring_expense_occurrences r
             JOIN transactions t ON t.id = r.transaction_id AND t.user_id = r.user_id
             WHERE r.user_id = :user_id
               AND t.deleted_at IS NULL
               AND t.transaction_date BETWEEN :date_from AND :date_to"
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':date_from' => $dateFrom,
            ':date_to' => $dateTo,
        ]);

        $value = $stmt->fetchColumn();
        return $value === false ? 0.0 : (float) $value;
    }

    private function countTransactions(int $userId, string $dateFrom, string $dateTo): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total
             FROM transactions
             WHERE user_id = :user_id
               AND deleted_at IS NULL
               AND transaction_date BETWEEN :date_from AND :date_to"
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':date_from' => $dateFrom,
            ':date_to' => $dateTo,
        ]);

        return (int) ($stmt->fetch()['total'] ?? 0);
    }

    /** @return list<array<string,mixed>> */
    private function queryRecentTransactionsForMonth(int $userId, string $dateFrom, string $dateTo): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
               t.id,
               t.transaction_date,
               t.expense,
               t.amount,
               t.category,
               t.is_split,
               tg.id AS tag_id,
               tg.name AS tag_name,
               tg.icon_key AS tag_icon_key,
               c.id AS card_id,
               c.name AS card_name,
               t.created_at,
               t.updated_at
             FROM transactions t
             JOIN tags tg ON tg.id = t.tag_id AND tg.user_id = t.user_id
             LEFT JOIN cards c ON c.id = t.card_id AND c.user_id = t.user_id
             WHERE t.user_id = :user_id
               AND t.deleted_at IS NULL
               AND t.transaction_date BETWEEN :date_from AND :date_to
             ORDER BY t.transaction_date DESC, t.id DESC
             LIMIT 10"
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':date_from' => $dateFrom,
            ':date_to' => $dateTo,
        ]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id' => (string) $row['id'],
                'date' => (string) $row['transaction_date'],
                'expense' => (string) $row['expense'],
                'amount' => $this->fmt((float) $row['amount']),
                'category' => (string) $row['category'],
                'is_split' => ((int) $row['is_split']) === 1,
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
                    ],
                'created_at' => (string) $row['created_at'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }

        return $items;
    }

    private function countMonthsInRange(DateTimeImmutable $startDate, DateTimeImmutable $endDate): int
    {
        $cursor = $startDate->modify('first day of this month');
        $lastMonth = $endDate->modify('first day of this month');
        $count = 0;

        while ($cursor <= $lastMonth) {
            $count++;
            $cursor = $cursor->modify('+1 month');
        }

        return max($count, 1);
    }

    /** @return array<string,mixed>|null */
    private function loadBudgetSettings(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT monthly_income, allocation_mode, needs_percent, wants_percent, savings_debts_percent, needs_amount, wants_amount, savings_debts_amount FROM budget_settings WHERE user_id = :user_id LIMIT 1'
        );
        $stmt->execute([':user_id' => $userId]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed>|null $settings
     *  @return array{monthly_income:float,budget_amounts:array{needs:float,wants:float,savings_debts:float}}
     */
    private function budgetPlanFromSettings(?array $settings): array
    {
        $income = $settings ? (float) $settings['monthly_income'] : 0.0;
        $mode = $settings ? (string) $settings['allocation_mode'] : 'percent';

        if ($mode === 'amount') {
            return [
                'monthly_income' => $income,
                'budget_amounts' => [
                    'needs' => (float) ($settings['needs_amount'] ?? 0.0),
                    'wants' => (float) ($settings['wants_amount'] ?? 0.0),
                    'savings_debts' => (float) ($settings['savings_debts_amount'] ?? 0.0),
                ],
            ];
        }

        $needsPercent = (float) ($settings['needs_percent'] ?? 50.0);
        $wantsPercent = (float) ($settings['wants_percent'] ?? 30.0);
        $savingsDebtsPercent = (float) ($settings['savings_debts_percent'] ?? 20.0);

        return [
            'monthly_income' => $income,
            'budget_amounts' => [
                'needs' => ($income * $needsPercent) / 100.0,
                'wants' => ($income * $wantsPercent) / 100.0,
                'savings_debts' => ($income * $savingsDebtsPercent) / 100.0,
            ],
        ];
    }

    private function fmt(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
