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

    public static function normalizeMonth(string $month, string $field = 'month'): string
    {
        $month = trim($month);
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be YYYY-MM'],
            ]);
        }

        $dt = DateTimeImmutable::createFromFormat('Y-m', $month, new DateTimeZone('UTC'));
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
}
