<?php

declare(strict_types=1);

namespace App\Budget;

use App\Http\HttpException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class BudgetSettingsResolver
{
    public const SETTING_COLUMNS = [
        'monthly_income',
        'income_source_type',
        'primary_monthly_income',
        'primary_hourly_rate',
        'primary_weekly_hours',
        'side_income_type',
        'side_income_label',
        'side_monthly_income',
        'side_hourly_rate',
        'side_weekly_hours',
        'allocation_mode',
        'needs_percent',
        'wants_percent',
        'savings_percent',
        'needs_amount',
        'wants_amount',
        'savings_amount',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{requested_month:string,resolved_effective_month:?string,is_exact_match:bool,settings:?array<string,mixed>} */
    public function getEffectiveSettingsForMonth(int $userId, string $month): array
    {
        $month = self::normalizeMonth($month);
        $effectiveMonth = $month . '-01';
        $columns = implode(', ', array_map(static fn(string $column): string => 'bsv.' . $column, self::SETTING_COLUMNS));

        $stmt = $this->pdo->prepare(
            "SELECT bsv.effective_month, {$columns}
             FROM budget_settings_versions bsv
             WHERE bsv.user_id = :user_id
               AND bsv.effective_month <= :effective_month
             ORDER BY bsv.effective_month DESC
             LIMIT 1"
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':effective_month' => $effectiveMonth,
        ]);

        $row = $stmt->fetch();
        if (!is_array($row)) {
            return [
                'requested_month' => $month,
                'resolved_effective_month' => null,
                'is_exact_match' => false,
                'settings' => null,
            ];
        }

        $resolvedMonth = substr((string) $row['effective_month'], 0, 7);
        unset($row['effective_month']);

        return [
            'requested_month' => $month,
            'resolved_effective_month' => $resolvedMonth,
            'is_exact_match' => $resolvedMonth === $month,
            'settings' => $row,
        ];
    }

    /** @return array<string,array{requested_month:string,resolved_effective_month:?string,is_exact_match:bool,settings:?array<string,mixed>}> */
    public function getEffectiveSettingsForRange(int $userId, string $startMonth, string $endMonth): array
    {
        $startMonth = self::normalizeMonth($startMonth);
        $endMonth = self::normalizeMonth($endMonth);
        if ($startMonth > $endMonth) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'month_range', 'message' => 'start month must be <= end month'],
            ]);
        }

        $items = [];
        $cursor = self::monthDate($startMonth);
        $lastMonth = self::monthDate($endMonth);

        while ($cursor <= $lastMonth) {
            $month = $cursor->format('Y-m');
            $items[$month] = $this->getEffectiveSettingsForMonth($userId, $month);
            $cursor = $cursor->modify('+1 month');
        }

        return $items;
    }

    /** @return array<string,mixed>|null */
    public function getLatestSettings(int $userId): ?array
    {
        $columns = implode(', ', self::SETTING_COLUMNS);
        $stmt = $this->pdo->prepare(
            "SELECT {$columns}
             FROM budget_settings_versions
             WHERE user_id = :user_id
             ORDER BY effective_month DESC
             LIMIT 1"
        );
        $stmt->execute([':user_id' => $userId]);

        $row = $stmt->fetch();
        if (is_array($row)) {
            return $row;
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . $columns . ' FROM budget_settings WHERE user_id = :user_id LIMIT 1'
        );
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed>|null $settings
     *  @return array{needs:float,wants:float,savings:float}
     */
    public function resolveAmounts(?array $settings): array
    {
        if ($settings === null) {
            return [
                'needs' => 0.0,
                'wants' => 0.0,
                'savings' => 0.0,
            ];
        }

        $resolved = $this->resolvedAmounts($settings);

        return [
            'needs' => (float) $resolved['needs'],
            'wants' => (float) $resolved['wants'],
            'savings' => (float) $resolved['savings'],
        ];
    }

    /**
     * @return array<int, array{
     *   effective_month:string,
     *   applies_from_month:string,
     *   applies_until_month:string|null,
     *   monthly_income:string,
     *   income_source_type:string,
     *   primary_monthly_income:string|null,
     *   primary_hourly_rate:string|null,
     *   primary_weekly_hours:string|null,
     *   side_income_type:string,
     *   side_income_label:string|null,
     *   side_monthly_income:string|null,
     *   side_hourly_rate:string|null,
     *   side_weekly_hours:string|null,
     *   allocation_mode:string,
     *   needs_percent:string|null,
     *   wants_percent:string|null,
     *   savings_percent:string|null,
     *   needs_amount:string|null,
     *   wants_amount:string|null,
     *   savings_amount:string|null,
     *   resolved_amounts:array{needs:string,wants:string,savings:string},
     *   created_at:string,
     *   updated_at:string
     * }>
     */
    public function getBudgetSettingsVersions(int $userId): array
    {
        $columns = implode(', ', array_map(static fn(string $column): string => 'bsv.' . $column, self::SETTING_COLUMNS));
        $stmt = $this->pdo->prepare(
            "SELECT bsv.effective_month, {$columns}, bsv.created_at, bsv.updated_at
             FROM budget_settings_versions bsv
             WHERE bsv.user_id = :user_id
             ORDER BY bsv.effective_month ASC"
        );
        $stmt->execute([':user_id' => $userId]);

        $rows = $stmt->fetchAll();
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $items = [];
        $rowCount = count($rows);
        for ($i = 0; $i < $rowCount; $i++) {
            $row = $rows[$i];
            if (!is_array($row)) {
                continue;
            }

            $effectiveMonth = substr((string) $row['effective_month'], 0, 7);
            $nextRow = $rows[$i + 1] ?? null;
            $appliesUntilMonth = null;
            if (is_array($nextRow)) {
                $appliesUntilMonth = self::monthDate(substr((string) $nextRow['effective_month'], 0, 7))
                    ->modify('-1 day')
                    ->format('Y-m');
            }

            $items[] = [
                'effective_month' => $effectiveMonth,
                'applies_from_month' => $effectiveMonth,
                'applies_until_month' => $appliesUntilMonth,
                'monthly_income' => $this->fmtDecimal((string) $row['monthly_income']),
                'income_source_type' => (string) $row['income_source_type'],
                'primary_monthly_income' => $row['primary_monthly_income'] === null ? null : $this->fmtDecimal((string) $row['primary_monthly_income']),
                'primary_hourly_rate' => $row['primary_hourly_rate'] === null ? null : $this->fmtDecimal((string) $row['primary_hourly_rate']),
                'primary_weekly_hours' => $row['primary_weekly_hours'] === null ? null : $this->fmtDecimal((string) $row['primary_weekly_hours']),
                'side_income_type' => (string) $row['side_income_type'],
                'side_income_label' => $row['side_income_label'] === null ? null : (string) $row['side_income_label'],
                'side_monthly_income' => $row['side_monthly_income'] === null ? null : $this->fmtDecimal((string) $row['side_monthly_income']),
                'side_hourly_rate' => $row['side_hourly_rate'] === null ? null : $this->fmtDecimal((string) $row['side_hourly_rate']),
                'side_weekly_hours' => $row['side_weekly_hours'] === null ? null : $this->fmtDecimal((string) $row['side_weekly_hours']),
                'allocation_mode' => (string) $row['allocation_mode'],
                'needs_percent' => $row['needs_percent'] === null ? null : $this->fmtDecimal((string) $row['needs_percent']),
                'wants_percent' => $row['wants_percent'] === null ? null : $this->fmtDecimal((string) $row['wants_percent']),
                'savings_percent' => $row['savings_percent'] === null ? null : $this->fmtDecimal((string) $row['savings_percent']),
                'needs_amount' => $row['needs_amount'] === null ? null : $this->fmtDecimal((string) $row['needs_amount']),
                'wants_amount' => $row['wants_amount'] === null ? null : $this->fmtDecimal((string) $row['wants_amount']),
                'savings_amount' => $row['savings_amount'] === null ? null : $this->fmtDecimal((string) $row['savings_amount']),
                'resolved_amounts' => $this->resolvedAmounts($row),
                'created_at' => (string) $row['created_at'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }

        return $items;
    }

    public static function normalizeMonth(string $month, string $field = 'month'): string
    {
        $month = trim($month);
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be YYYY-MM'],
            ]);
        }

        // Reset omitted date/time fields before parsing. Without the `!`, PHP
        // carries the current day into the result; on dates such as July 30,
        // parsing `2026-06` can roll into July and incorrectly reject a valid
        // historical month.
        $dt = DateTimeImmutable::createFromFormat('!Y-m', $month, new DateTimeZone('UTC'));
        if (!$dt || $dt->format('Y-m') !== $month) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a valid month'],
            ]);
        }

        return $month;
    }

    private static function monthDate(string $month): DateTimeImmutable
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $month . '-01', new DateTimeZone('UTC'));
        if (!$dt) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'month', 'message' => 'must be a valid month'],
            ]);
        }

        return $dt;
    }

    /** @param array<string,mixed> $row
     *  @return array{needs:string,wants:string,savings:string}
     */
    private function resolvedAmounts(array $row): array
    {
        if (($row['allocation_mode'] ?? null) === 'amount') {
            return [
                'needs' => $this->fmtDecimal((string) $row['needs_amount']),
                'wants' => $this->fmtDecimal((string) $row['wants_amount']),
                'savings' => $this->fmtDecimal((string) $row['savings_amount']),
            ];
        }

        return [
            'needs' => $this->percentToMoney((string) $row['monthly_income'], (string) $row['needs_percent']),
            'wants' => $this->percentToMoney((string) $row['monthly_income'], (string) $row['wants_percent']),
            // Keep savings as the remainder so the resolved categories always total monthly income.
            'savings' => $this->fmtDecimal((string) (
                (float) $row['monthly_income']
                - (float) $this->percentToMoney((string) $row['monthly_income'], (string) $row['needs_percent'])
                - (float) $this->percentToMoney((string) $row['monthly_income'], (string) $row['wants_percent'])
            )),
        ];
    }

    private function percentToMoney(string $monthlyIncome, string $percent): string
    {
        $cents = (int) round(((float) $monthlyIncome * (float) $percent));

        return $this->fmtCents($cents);
    }

    private function fmtCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function fmtDecimal(string $decimal): string
    {
        return number_format((float) $decimal, 2, '.', '');
    }
}
