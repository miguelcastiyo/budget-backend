<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Budget\BudgetSettingsResolver;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class MonthOverviewController
{
    private const CATEGORY_ORDER = ['needs', 'wants', 'savings'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthService $auth,
        private readonly BudgetSettingsResolver $budgetSettingsResolver
    ) {
    }

    /** @param array{month:string} $params */
    public function overview(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $month = $this->validatedMonth((string) ($params['month'] ?? ''));
        [$dateFrom, $dateTo] = $this->monthDateRange($month);

        $budgetResolution = $this->budgetSettingsResolver->getEffectiveSettingsForMonth($ctx->userId(), $month);
        $budgetPlan = $this->budgetPlanFromSettings($budgetResolution['settings']);
        $totalBudgeted = array_sum(array_map(static fn(string $value): float => (float) $value, $budgetPlan['budget_amounts']));
        $actualByCategory = $this->queryCategoryTotals($ctx->userId(), $dateFrom, $dateTo);
        $summary = $this->queryMonthlySummary($ctx->userId(), $dateFrom, $dateTo);
        $categories = $this->buildCategories($budgetPlan, $actualByCategory);
        $totalRemaining = $totalBudgeted - $summary['total_spent'];
        $recurringSummary = $this->queryRecurringSummary($ctx->userId(), $month);
        $recentTransactions = $this->queryRecentTransactions($ctx->userId(), $dateFrom, $dateTo, 5);

        return Response::json([
            'month' => $month,
            'budget' => [
                'requested_month' => $budgetResolution['requested_month'],
                'resolved_effective_month' => $budgetResolution['resolved_effective_month'],
                'is_exact_match' => $budgetResolution['is_exact_match'],
                'monthly_income' => $budgetResolution['settings'] === null ? null : $this->fmt((float) $budgetPlan['monthly_income']),
                'allocation_mode' => $budgetResolution['settings'] === null ? null : (string) $budgetResolution['settings']['allocation_mode'],
                'resolved_amounts' => $budgetPlan['budget_amounts'],
            ],
            'summary' => [
                'total_budgeted' => $this->fmt($totalBudgeted),
                'total_spent' => $this->fmt($summary['total_spent']),
                'total_remaining' => $this->fmt($totalRemaining),
                'percent_used' => $this->fmt($totalBudgeted > 0.0 ? ($summary['total_spent'] / $totalBudgeted) * 100.0 : 0.0),
                'transaction_count' => $summary['transaction_count'],
                'avg_transaction' => $this->fmt($summary['avg_transaction']),
            ],
            'month_progress' => $this->monthProgress($month, $totalRemaining),
            'categories' => $categories,
            'recurring' => $recurringSummary,
            'recent_transactions' => $recentTransactions,
            'status_cards' => $this->statusCards($budgetResolution, $summary, $categories, $recurringSummary),
        ]);
    }

    private function validatedMonth(string $month): string
    {
        return BudgetSettingsResolver::normalizeMonth($month, 'month');
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

    /** @param array<string,mixed>|null $settings
     *  @return array{monthly_income:float,budget_amounts:array{needs:string,wants:string,savings:string}}
     */
    private function budgetPlanFromSettings(?array $settings): array
    {
        if ($settings === null) {
            return [
                'monthly_income' => 0.0,
                'budget_amounts' => [
                    'needs' => $this->fmt(0.0),
                    'wants' => $this->fmt(0.0),
                    'savings' => $this->fmt(0.0),
                ],
            ];
        }

        $income = (float) $settings['monthly_income'];
        $mode = (string) $settings['allocation_mode'];

        if ($mode === 'amount') {
            return [
                'monthly_income' => $income,
                'budget_amounts' => [
                    'needs' => $this->fmt((float) ($settings['needs_amount'] ?? 0.0)),
                    'wants' => $this->fmt((float) ($settings['wants_amount'] ?? 0.0)),
                    'savings' => $this->fmt((float) ($settings['savings_amount'] ?? 0.0)),
                ],
            ];
        }

        $needsPercent = (float) ($settings['needs_percent'] ?? 50.0);
        $wantsPercent = (float) ($settings['wants_percent'] ?? 30.0);
        $savingsPercent = (float) ($settings['savings_percent'] ?? 20.0);

        return [
            'monthly_income' => $income,
            'budget_amounts' => [
                'needs' => $this->fmt(($income * $needsPercent) / 100.0),
                'wants' => $this->fmt(($income * $wantsPercent) / 100.0),
                'savings' => $this->fmt(($income * $savingsPercent) / 100.0),
            ],
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

    /** @return array{total_spent:float,transaction_count:int,avg_transaction:float} */
    private function queryMonthlySummary(int $userId, string $dateFrom, string $dateTo): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
               COALESCE(SUM(amount), 0) AS total_spent,
               COUNT(*) AS transaction_count,
               COALESCE(AVG(amount), 0) AS avg_transaction
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
            'avg_transaction' => (float) ($row['avg_transaction'] ?? 0.0),
        ];
    }

    /**
     * @return list<array{category:string,budget_amount:string,actual_spend:string,remaining:string,percent_used:string,status:'under'|'near'|'over'}>
     */
    private function buildCategories(array $budgetPlan, array $actualByCategory): array
    {
        $items = [];
        foreach (self::CATEGORY_ORDER as $category) {
            $budgetAmount = (float) ($budgetPlan['budget_amounts'][$category] ?? 0.0);
            $actualSpend = (float) ($actualByCategory[$category] ?? 0.0);
            $remaining = $budgetAmount - $actualSpend;
            $percentUsed = $budgetAmount > 0.0 ? ($actualSpend / $budgetAmount) * 100.0 : 0.0;

            $items[] = [
                'category' => $category,
                'budget_amount' => $this->fmt($budgetAmount),
                'actual_spend' => $this->fmt($actualSpend),
                'remaining' => $this->fmt($remaining),
                'percent_used' => $this->fmt($percentUsed),
                'status' => $percentUsed < 85.0 ? 'under' : ($percentUsed <= 100.0 ? 'near' : 'over'),
            ];
        }

        return $items;
    }

    /** @return array{days_in_month:int,days_elapsed:int,days_remaining:int,percent_elapsed:string,daily_safe_to_spend:string} */
    private function monthProgress(string $month, float $totalRemaining): array
    {
        $monthStart = DateTimeImmutable::createFromFormat('Y-m-d', $month . '-01', new DateTimeZone('UTC'));
        if (!$monthStart) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'month', 'message' => 'must be a valid month'],
            ]);
        }

        $daysInMonth = (int) $monthStart->modify('last day of this month')->format('d');
        $currentUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $currentMonth = $currentUtc->format('Y-m');
        $daysElapsed = match (true) {
            $month === $currentMonth => (int) $currentUtc->format('d'),
            $month < $currentMonth => $daysInMonth,
            default => 0,
        };
        $daysRemaining = $daysInMonth - $daysElapsed;

        return [
            'days_in_month' => $daysInMonth,
            'days_elapsed' => $daysElapsed,
            'days_remaining' => $daysRemaining,
            'percent_elapsed' => $this->fmt($daysInMonth > 0 ? ($daysElapsed / $daysInMonth) * 100.0 : 0.0),
            'daily_safe_to_spend' => $this->fmt($daysRemaining > 0 ? max($totalRemaining, 0.0) / $daysRemaining : max($totalRemaining, 0.0)),
        ];
    }

    /** @return array{committed_total:string,generated_total:string,ungenerated_total:string,generated_count:int,ungenerated_count:int,items_count:int} */
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

        $ungeneratedCount = $itemsCount - $generatedCount;

        return [
            'committed_total' => $this->fmt($committedTotal),
            'generated_total' => $this->fmt($generatedTotal),
            'ungenerated_total' => $this->fmt($committedTotal - $generatedTotal),
            'generated_count' => $generatedCount,
            'ungenerated_count' => $ungeneratedCount,
            'items_count' => $itemsCount,
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
  t.source,
  tg.id AS tag_id,
  tg.name AS tag_name,
  tg.icon_key AS tag_icon_key,
  c.id AS card_id,
  c.name AS card_name,
  reo.recurring_expense_id,
  t.created_at,
  t.updated_at
FROM transactions t
JOIN tags tg ON tg.id = t.tag_id AND tg.user_id = t.user_id
LEFT JOIN cards c ON c.id = t.card_id AND c.user_id = t.user_id
LEFT JOIN (
  SELECT user_id, transaction_id, MIN(recurring_expense_id) AS recurring_expense_id
  FROM recurring_expense_occurrences
  WHERE transaction_id IS NOT NULL
  GROUP BY user_id, transaction_id
) reo ON reo.transaction_id = t.id AND reo.user_id = t.user_id
WHERE t.user_id = :user_id
  AND t.deleted_at IS NULL
  AND t.transaction_date BETWEEN :date_from AND :date_to
ORDER BY t.transaction_date DESC, t.id DESC
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
                    ],
                'created_at' => (string) $row['created_at'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }

        return $items;
    }

    /**
     * @param array{requested_month:string,resolved_effective_month:?string,is_exact_match:bool,settings:?array<string,mixed>} $budgetResolution
     * @param array{total_spent:float,transaction_count:int,avg_transaction:float} $summary
     * @param list<array{category:string,budget_amount:string,actual_spend:string,remaining:string,percent_used:string,status:'under'|'near'|'over'}> $categories
     * @param array{committed_total:string,generated_total:string,ungenerated_total:string,generated_count:int,ungenerated_count:int,items_count:int} $recurringSummary
     * @return list<array{type:'positive'|'warning'|'neutral',title:string,body:string}>
     */
    private function statusCards(array $budgetResolution, array $summary, array $categories, array $recurringSummary): array
    {
        $cards = [];

        if ($budgetResolution['settings'] === null) {
            $cards[] = [
                'type' => 'neutral',
                'title' => 'No budget for this month',
                'body' => 'Create a budget to see monthly targets and progress.',
            ];
        }

        foreach ($categories as $category) {
            if ($category['status'] !== 'over') {
                continue;
            }

            $cards[] = [
                'type' => 'warning',
                'title' => ucfirst($category['category']) . ' is over budget',
                'body' => ucfirst($category['category']) . ' spending is ' . $category['percent_used'] . '% of its monthly target.',
            ];
            break;
        }

        foreach ($categories as $category) {
            if ($category['status'] !== 'near') {
                continue;
            }

            $cards[] = [
                'type' => 'warning',
                'title' => ucfirst($category['category']) . ' is near its limit',
                'body' => ucfirst($category['category']) . ' spending is ' . $category['percent_used'] . '% of its monthly target.',
            ];
            break;
        }

        if ($summary['transaction_count'] === 0) {
            $cards[] = [
                'type' => 'neutral',
                'title' => 'No transactions yet',
                'body' => 'Add transactions to start tracking this month.',
            ];
        }

        if ($budgetResolution['settings'] !== null && $summary['total_spent'] < array_sum(array_map(
            static fn(array $category): float => (float) $category['budget_amount'],
            $categories
        ))) {
            $cards[] = [
                'type' => 'positive',
                'title' => 'Month is on track',
                'body' => 'Spending is currently below the monthly budget.',
            ];
        }

        if ($recurringSummary['ungenerated_count'] > 0) {
            $cards[] = [
                'type' => 'neutral',
                'title' => 'Recurring expenses pending',
                'body' => 'Some recurring expenses have not been generated for this month.',
            ];
        }

        return array_slice($cards, 0, 3);
    }

    private function fmt(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
