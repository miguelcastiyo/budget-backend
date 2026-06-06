<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Budget\BudgetSettingsResolver;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use PDO;
use Throwable;

final class BudgetSettingsController
{
    private const WEEKS_PER_MONTH = 52 / 12;
    private const SETTING_COLUMNS = [
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
        'savings_debts_percent',
        'needs_amount',
        'wants_amount',
        'savings_debts_amount',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthService $auth,
        private readonly BudgetSettingsResolver $resolver
    ) {
    }

    public function get(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);

        $month = trim((string) ($request->query['month'] ?? ''));
        if ($month !== '') {
            $resolved = $this->resolver->getEffectiveSettingsForMonth($ctx->userId(), $month);

            return Response::json([
                'requested_month' => $resolved['requested_month'],
                'resolved_effective_month' => $resolved['resolved_effective_month'],
                'is_exact_match' => $resolved['is_exact_match'],
                'settings' => $resolved['settings'] === null
                    ? $this->defaultSettings()
                    : $this->normalizeRow($resolved['settings']),
            ]);
        }

        $row = $this->resolver->getLatestSettings($ctx->userId());
        if (!$row) {
            return Response::json($this->defaultSettings());
        }

        return Response::json($this->normalizeRow($row));
    }

    public function upsert(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $payload = $request->json();
        $settings = $this->settingsFromPayload($payload);
        $effectiveMonth = $this->effectiveMonthFromPayload($payload);

        $this->pdo->beginTransaction();
        try {
            $this->upsertVersionRow($ctx->userId(), $effectiveMonth, $settings);
            $latest = $this->resolver->getLatestSettings($ctx->userId()) ?? $settings;
            $this->upsertCompatibilityRow($ctx->userId(), $latest);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return Response::json($settings);
    }

    /** @param array<string,mixed> $settings */
    private function upsertCompatibilityRow(int $userId, array $settings): void
    {
        $exists = $this->pdo->prepare('SELECT id FROM budget_settings WHERE user_id = :user_id LIMIT 1');
        $exists->execute([':user_id' => $userId]);
        $row = $exists->fetch();

        if ($row) {
            $assignments = implode(",\n  ", array_map(
                static fn(string $column): string => $column . ' = :' . $column,
                self::SETTING_COLUMNS
            ));
            $sql = <<<'SQL'
UPDATE budget_settings
SET
  %s,
  updated_at = CURRENT_TIMESTAMP
WHERE user_id = :user_id
SQL;
            $sql = sprintf($sql, $assignments);
            $stmt = $this->pdo->prepare($sql);
        } else {
            $columns = implode(",\n  ", array_merge(['user_id'], self::SETTING_COLUMNS));
            $placeholders = implode(",\n  ", array_map(
                static fn(string $column): string => ':' . $column,
                array_merge(['user_id'], self::SETTING_COLUMNS)
            ));
            $sql = <<<'SQL'
INSERT INTO budget_settings (
  %s
)
VALUES (
  %s
)
SQL;
            $sql = sprintf($sql, $columns, $placeholders);
            $stmt = $this->pdo->prepare($sql);
        }

        $stmt->execute($this->statementParams($userId, $settings));
    }

    /** @param array<string,string|null> $settings */
    private function upsertVersionRow(int $userId, string $effectiveMonth, array $settings): void
    {
        $columns = array_merge(['user_id', 'effective_month'], self::SETTING_COLUMNS);
        $insertColumns = implode(",\n  ", $columns);
        $placeholders = implode(",\n  ", array_map(static fn(string $column): string => ':' . $column, $columns));
        $assignments = implode(",\n  ", array_map(
            static fn(string $column): string => $column . ' = VALUES(' . $column . ')',
            self::SETTING_COLUMNS
        ));

        $sql = sprintf(
            <<<'SQL'
INSERT INTO budget_settings_versions (
  %s
)
VALUES (
  %s
)
ON DUPLICATE KEY UPDATE
  %s,
  updated_at = CURRENT_TIMESTAMP
SQL,
            $insertColumns,
            $placeholders,
            $assignments
        );

        $params = $this->statementParams($userId, $settings);
        $params[':effective_month'] = $effectiveMonth . '-01';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /** @param array<string,mixed> $settings
     *  @return array<string,mixed>
     */
    private function statementParams(int $userId, array $settings): array
    {
        $params = [':user_id' => $userId];
        foreach (self::SETTING_COLUMNS as $column) {
            $params[':' . $column] = $settings[$column];
        }

        return $params;
    }

    /** @param array<string,mixed> $payload */
    private function effectiveMonthFromPayload(array $payload): string
    {
        if (!array_key_exists('effective_month', $payload) || $payload['effective_month'] === null || $payload['effective_month'] === '') {
            return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m');
        }

        if (!is_string($payload['effective_month'])) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'effective_month', 'message' => 'must be YYYY-MM'],
            ]);
        }

        return BudgetSettingsResolver::normalizeMonth($payload['effective_month'], 'effective_month');
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,string|null>
     */
    private function settingsFromPayload(array $payload): array
    {
        $monthlyIncome = $this->decimalString($payload['monthly_income'] ?? null, 'monthly_income');
        $allocationMode = (string) ($payload['allocation_mode'] ?? '');
        $incomeBreakdown = $this->incomeBreakdownFromPayload($payload, $monthlyIncome);

        if (!in_array($allocationMode, ['percent', 'amount'], true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'allocation_mode', 'message' => 'must be percent or amount'],
            ]);
        }

        $settings = [
            'monthly_income' => $monthlyIncome,
            ...$incomeBreakdown,
            'allocation_mode' => $allocationMode,
            'needs_percent' => null,
            'wants_percent' => null,
            'savings_debts_percent' => null,
            'needs_amount' => null,
            'wants_amount' => null,
            'savings_debts_amount' => null,
        ];

        if ($allocationMode === 'percent') {
            $needs = $this->decimalString($payload['needs_percent'] ?? null, 'needs_percent');
            $wants = $this->decimalString($payload['wants_percent'] ?? null, 'wants_percent');
            $savingsDebts = $this->decimalString($payload['savings_debts_percent'] ?? null, 'savings_debts_percent');

            $sum = $this->asCents($needs) + $this->asCents($wants) + $this->asCents($savingsDebts);
            if ($sum !== 10000) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'allocation_mode', 'message' => 'percent values must total 100.00'],
                ]);
            }

            $settings['needs_percent'] = $needs;
            $settings['wants_percent'] = $wants;
            $settings['savings_debts_percent'] = $savingsDebts;
        }

        if ($allocationMode === 'amount') {
            $needs = $this->decimalString($payload['needs_amount'] ?? null, 'needs_amount');
            $wants = $this->decimalString($payload['wants_amount'] ?? null, 'wants_amount');
            $savingsDebts = $this->decimalString($payload['savings_debts_amount'] ?? null, 'savings_debts_amount');

            $sum = $this->asCents($needs) + $this->asCents($wants) + $this->asCents($savingsDebts);
            if ($sum !== $this->asCents($monthlyIncome)) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'allocation_mode', 'message' => 'amount values must total monthly_income'],
                ]);
            }

            $settings['needs_amount'] = $needs;
            $settings['wants_amount'] = $wants;
            $settings['savings_debts_amount'] = $savingsDebts;
        }

        return $settings;
    }

    /** @param array<string,mixed> $row */
    private function normalizeRow(array $row): array
    {
        return [
            'monthly_income' => $this->fmt((string) $row['monthly_income']),
            'income_source_type' => (string) ($row['income_source_type'] ?? 'monthly'),
            'primary_monthly_income' => $row['primary_monthly_income'] === null ? null : $this->fmt((string) $row['primary_monthly_income']),
            'primary_hourly_rate' => $row['primary_hourly_rate'] === null ? null : $this->fmt((string) $row['primary_hourly_rate']),
            'primary_weekly_hours' => $row['primary_weekly_hours'] === null ? null : $this->fmt((string) $row['primary_weekly_hours']),
            'side_income_type' => (string) ($row['side_income_type'] ?? 'none'),
            'side_income_label' => $row['side_income_label'] === null ? null : (string) $row['side_income_label'],
            'side_monthly_income' => $row['side_monthly_income'] === null ? null : $this->fmt((string) $row['side_monthly_income']),
            'side_hourly_rate' => $row['side_hourly_rate'] === null ? null : $this->fmt((string) $row['side_hourly_rate']),
            'side_weekly_hours' => $row['side_weekly_hours'] === null ? null : $this->fmt((string) $row['side_weekly_hours']),
            'allocation_mode' => (string) $row['allocation_mode'],
            'needs_percent' => $row['needs_percent'] === null ? null : $this->fmt((string) $row['needs_percent']),
            'wants_percent' => $row['wants_percent'] === null ? null : $this->fmt((string) $row['wants_percent']),
            'savings_debts_percent' => $row['savings_debts_percent'] === null ? null : $this->fmt((string) $row['savings_debts_percent']),
            'needs_amount' => $row['needs_amount'] === null ? null : $this->fmt((string) $row['needs_amount']),
            'wants_amount' => $row['wants_amount'] === null ? null : $this->fmt((string) $row['wants_amount']),
            'savings_debts_amount' => $row['savings_debts_amount'] === null ? null : $this->fmt((string) $row['savings_debts_amount']),
        ];
    }

    /** @return array<string,mixed> */
    private function defaultSettings(): array
    {
        return [
            'monthly_income' => '0.00',
            'income_source_type' => 'monthly',
            'primary_monthly_income' => '0.00',
            'primary_hourly_rate' => null,
            'primary_weekly_hours' => null,
            'side_income_type' => 'none',
            'side_income_label' => null,
            'side_monthly_income' => null,
            'side_hourly_rate' => null,
            'side_weekly_hours' => null,
            'allocation_mode' => 'percent',
            'needs_percent' => '50.00',
            'wants_percent' => '30.00',
            'savings_debts_percent' => '20.00',
            'needs_amount' => null,
            'wants_amount' => null,
            'savings_debts_amount' => null,
        ];
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,string|null>
     */
    private function incomeBreakdownFromPayload(array $payload, string $monthlyIncome): array
    {
        if (!array_key_exists('income_source_type', $payload)) {
            return [
                'income_source_type' => 'monthly',
                'primary_monthly_income' => $monthlyIncome,
                'primary_hourly_rate' => null,
                'primary_weekly_hours' => null,
                'side_income_type' => 'none',
                'side_income_label' => null,
                'side_monthly_income' => null,
                'side_hourly_rate' => null,
                'side_weekly_hours' => null,
            ];
        }

        $incomeSourceType = (string) ($payload['income_source_type'] ?? '');
        if (!in_array($incomeSourceType, ['monthly', 'hourly'], true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'income_source_type', 'message' => 'must be monthly or hourly'],
            ]);
        }

        $sideIncomeType = (string) ($payload['side_income_type'] ?? 'none');
        if (!in_array($sideIncomeType, ['none', 'monthly', 'hourly'], true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'side_income_type', 'message' => 'must be none, monthly, or hourly'],
            ]);
        }

        $breakdown = [
            'income_source_type' => $incomeSourceType,
            'primary_monthly_income' => null,
            'primary_hourly_rate' => null,
            'primary_weekly_hours' => null,
            'side_income_type' => $sideIncomeType,
            'side_income_label' => $sideIncomeType === 'none' ? null : $this->optionalLabel($payload['side_income_label'] ?? null),
            'side_monthly_income' => null,
            'side_hourly_rate' => null,
            'side_weekly_hours' => null,
        ];

        $computedCents = 0;

        if ($incomeSourceType === 'monthly') {
            $primaryMonthlyIncome = $this->decimalString($payload['primary_monthly_income'] ?? null, 'primary_monthly_income');
            $computedCents += $this->asCents($primaryMonthlyIncome);
            $breakdown['primary_monthly_income'] = $primaryMonthlyIncome;
        } else {
            $primaryHourlyRate = $this->positiveDecimalString($payload['primary_hourly_rate'] ?? null, 'primary_hourly_rate');
            $primaryWeeklyHours = $this->positiveDecimalString($payload['primary_weekly_hours'] ?? null, 'primary_weekly_hours');
            $computedCents += $this->hourlyMonthlyCents($primaryHourlyRate, $primaryWeeklyHours);
            $breakdown['primary_hourly_rate'] = $primaryHourlyRate;
            $breakdown['primary_weekly_hours'] = $primaryWeeklyHours;
        }

        if ($sideIncomeType === 'monthly') {
            $sideMonthlyIncome = $this->positiveDecimalString($payload['side_monthly_income'] ?? null, 'side_monthly_income');
            $computedCents += $this->asCents($sideMonthlyIncome);
            $breakdown['side_monthly_income'] = $sideMonthlyIncome;
        }

        if ($sideIncomeType === 'hourly') {
            $sideHourlyRate = $this->positiveDecimalString($payload['side_hourly_rate'] ?? null, 'side_hourly_rate');
            $sideWeeklyHours = $this->positiveDecimalString($payload['side_weekly_hours'] ?? null, 'side_weekly_hours');
            $computedCents += $this->hourlyMonthlyCents($sideHourlyRate, $sideWeeklyHours);
            $breakdown['side_hourly_rate'] = $sideHourlyRate;
            $breakdown['side_weekly_hours'] = $sideWeeklyHours;
        }

        if ($computedCents !== $this->asCents($monthlyIncome)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'monthly_income', 'message' => 'must match the income source breakdown'],
            ]);
        }

        return $breakdown;
    }

    private function decimalString(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^\d+(\.\d{2})$/', $value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a decimal string like 123.45'],
            ]);
        }

        return $this->fmt($value);
    }

    private function positiveDecimalString(mixed $value, string $field): string
    {
        $decimal = $this->decimalString($value, $field);
        if ($this->asCents($decimal) <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be greater than 0.00'],
            ]);
        }

        return $decimal;
    }

    private function optionalLabel(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'side_income_label', 'message' => 'must be a string'],
            ]);
        }

        $label = trim($value);
        if ($label === '') {
            return null;
        }

        if (strlen($label) > 80) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'side_income_label', 'message' => 'must be 80 characters or fewer'],
            ]);
        }

        return $label;
    }

    private function hourlyMonthlyCents(string $hourlyRate, string $weeklyHours): int
    {
        return (int) round((float) $hourlyRate * (float) $weeklyHours * self::WEEKS_PER_MONTH * 100);
    }

    private function asCents(string $decimal): int
    {
        return (int) str_replace('.', '', $decimal);
    }

    private function fmt(string $decimal): string
    {
        return number_format((float) $decimal, 2, '.', '');
    }
}
