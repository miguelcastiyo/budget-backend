<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use PDO;

final class BudgetSettingsController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthService $auth
    ) {
    }

    public function get(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);

        $stmt = $this->pdo->prepare(
            'SELECT monthly_income, allocation_mode, needs_percent, wants_percent, savings_debts_percent, needs_amount, wants_amount, savings_debts_amount FROM budget_settings WHERE user_id = :user_id LIMIT 1'
        );
        $stmt->execute([':user_id' => $ctx->userId()]);
        $row = $stmt->fetch();

        if (!$row) {
            return Response::json([
                'monthly_income' => '0.00',
                'allocation_mode' => 'percent',
                'needs_percent' => '50.00',
                'wants_percent' => '30.00',
                'savings_debts_percent' => '20.00',
                'needs_amount' => null,
                'wants_amount' => null,
                'savings_debts_amount' => null,
            ]);
        }

        return Response::json($this->normalizeRow($row));
    }

    public function upsert(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $payload = $request->json();

        $monthlyIncome = $this->decimalString($payload['monthly_income'] ?? null, 'monthly_income');
        $allocationMode = (string) ($payload['allocation_mode'] ?? '');

        if (!in_array($allocationMode, ['percent', 'amount'], true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'allocation_mode', 'message' => 'must be percent or amount'],
            ]);
        }

        $settings = [
            'monthly_income' => $monthlyIncome,
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

        $exists = $this->pdo->prepare('SELECT id FROM budget_settings WHERE user_id = :user_id LIMIT 1');
        $exists->execute([':user_id' => $ctx->userId()]);
        $row = $exists->fetch();

        if ($row) {
            $sql = <<<'SQL'
UPDATE budget_settings
SET
  monthly_income = :monthly_income,
  allocation_mode = :allocation_mode,
  needs_percent = :needs_percent,
  wants_percent = :wants_percent,
  savings_debts_percent = :savings_debts_percent,
  needs_amount = :needs_amount,
  wants_amount = :wants_amount,
  savings_debts_amount = :savings_debts_amount,
  updated_at = CURRENT_TIMESTAMP
WHERE user_id = :user_id
SQL;
            $stmt = $this->pdo->prepare($sql);
        } else {
            $sql = <<<'SQL'
INSERT INTO budget_settings (
  user_id,
  monthly_income,
  allocation_mode,
  needs_percent,
  wants_percent,
  savings_debts_percent,
  needs_amount,
  wants_amount,
  savings_debts_amount
)
VALUES (
  :user_id,
  :monthly_income,
  :allocation_mode,
  :needs_percent,
  :wants_percent,
  :savings_debts_percent,
  :needs_amount,
  :wants_amount,
  :savings_debts_amount
)
SQL;
            $stmt = $this->pdo->prepare($sql);
        }

        $stmt->execute([
            ':user_id' => $ctx->userId(),
            ':monthly_income' => $settings['monthly_income'],
            ':allocation_mode' => $settings['allocation_mode'],
            ':needs_percent' => $settings['needs_percent'],
            ':wants_percent' => $settings['wants_percent'],
            ':savings_debts_percent' => $settings['savings_debts_percent'],
            ':needs_amount' => $settings['needs_amount'],
            ':wants_amount' => $settings['wants_amount'],
            ':savings_debts_amount' => $settings['savings_debts_amount'],
        ]);

        return Response::json($settings);
    }

    /** @param array<string,mixed> $row */
    private function normalizeRow(array $row): array
    {
        return [
            'monthly_income' => $this->fmt((string) $row['monthly_income']),
            'allocation_mode' => (string) $row['allocation_mode'],
            'needs_percent' => $row['needs_percent'] === null ? null : $this->fmt((string) $row['needs_percent']),
            'wants_percent' => $row['wants_percent'] === null ? null : $this->fmt((string) $row['wants_percent']),
            'savings_debts_percent' => $row['savings_debts_percent'] === null ? null : $this->fmt((string) $row['savings_debts_percent']),
            'needs_amount' => $row['needs_amount'] === null ? null : $this->fmt((string) $row['needs_amount']),
            'wants_amount' => $row['wants_amount'] === null ? null : $this->fmt((string) $row['wants_amount']),
            'savings_debts_amount' => $row['savings_debts_amount'] === null ? null : $this->fmt((string) $row['savings_debts_amount']),
        ];
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

    private function asCents(string $decimal): int
    {
        return (int) str_replace('.', '', $decimal);
    }

    private function fmt(string $decimal): string
    {
        return number_format((float) $decimal, 2, '.', '');
    }
}
