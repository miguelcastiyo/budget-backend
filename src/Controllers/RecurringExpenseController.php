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

final class RecurringExpenseController
{
    private const ALLOWED_CATEGORIES = ['needs', 'wants', 'savings_debts'];
    private const ALLOWED_BILLING_TYPES = ['day_of_month', 'last_day'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthService $auth,
        private readonly RecurringExpenseService $recurring
    ) {
    }

    public function list(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $monthRaw = trim((string) ($request->query['month'] ?? ''));
        $month = $monthRaw === '' ? $this->recurring->currentMonth() : $this->recurring->normalizeMonth($monthRaw);
        $instanceMonth = $month . '-01';

        $this->recurring->ensureGeneratedForMonth($ctx->userId(), $month);

        $totalStmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) AS committed_total
             FROM recurring_expenses
             WHERE user_id = :user_id
               AND is_active = 1
               AND deleted_at IS NULL
               AND starts_month <= :instance_month_start
               AND (ends_month IS NULL OR ends_month >= :instance_month_end)'
        );
        $totalStmt->execute([
            ':user_id' => $ctx->userId(),
            ':instance_month_start' => $instanceMonth,
            ':instance_month_end' => $instanceMonth,
        ]);
        $committedTotal = (float) ($totalStmt->fetch()['committed_total'] ?? 0);

        $sql = <<<'SQL'
SELECT
  re.id,
  re.expense,
  re.amount,
  re.category,
  re.tag_id,
  re.card_id,
  re.billing_type,
  re.billing_day,
  re.starts_month,
  re.ends_month,
  re.is_active,
  re.created_at,
  re.updated_at,
  tg.name AS tag_name,
  tg.icon_key AS tag_icon_key,
  c.name AS card_name,
  EXISTS(
    SELECT 1
    FROM recurring_expense_occurrences reo
    WHERE reo.user_id = re.user_id
      AND reo.recurring_expense_id = re.id
      AND reo.occurrence_month = :instance_month
    LIMIT 1
  ) AS generated_for_month
FROM recurring_expenses re
JOIN tags tg ON tg.id = re.tag_id AND tg.user_id = re.user_id
LEFT JOIN cards c ON c.id = re.card_id AND c.user_id = re.user_id
WHERE re.user_id = :user_id
  AND re.deleted_at IS NULL
ORDER BY re.is_active DESC, re.expense ASC, re.id ASC
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':instance_month' => $instanceMonth,
            ':user_id' => $ctx->userId(),
        ]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $billingType = (string) $row['billing_type'];
            $billingDay = $row['billing_day'] === null ? null : (int) $row['billing_day'];

            $items[] = [
                'id' => (string) $row['id'],
                'expense' => (string) $row['expense'],
                'amount' => $this->fmt((string) $row['amount']),
                'category' => (string) $row['category'],
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
                'billing_type' => $billingType,
                'billing_day' => $billingDay,
                'projected_date_for_month' => $this->projectedDateForMonth($month, $billingType, $billingDay),
                'starts_month' => substr((string) $row['starts_month'], 0, 7),
                'ends_month' => $row['ends_month'] === null ? null : substr((string) $row['ends_month'], 0, 7),
                'is_active' => (bool) $row['is_active'],
                'generated_for_month' => (bool) $row['generated_for_month'],
                'created_at' => (string) $row['created_at'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }

        return Response::json([
            'month' => $month,
            'committed_total' => $this->fmt((string) $committedTotal),
            'items_count' => count($items),
            'items' => $items,
        ]);
    }

    public function create(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $payload = $request->json();

        $expense = $this->validatedExpense($payload['expense'] ?? null);
        $amount = $this->validatedMoney($payload['amount'] ?? null, 'amount');
        $category = $this->validatedCategory($payload['category'] ?? null);
        $tagId = $this->resolvedTagId($ctx->userId(), $payload['tag_id'] ?? null);
        $cardId = $this->resolvedCardId($ctx->userId(), $payload['card_id'] ?? null);
        $billingType = $this->validatedBillingType($payload['billing_type'] ?? null);
        $billingDay = $this->validatedBillingDay($payload['billing_day'] ?? null, $billingType);
        $startsMonth = $this->validatedMonth($payload['starts_month'] ?? $this->recurring->currentMonth(), 'starts_month');
        $endsMonth = $this->validatedNullableMonth($payload['ends_month'] ?? null, 'ends_month');
        $seedTransaction = array_key_exists('seed_transaction_id', $payload)
            ? $this->resolvedSeedTransaction($ctx->userId(), $payload['seed_transaction_id'])
            : null;
        $isActive = array_key_exists('is_active', $payload)
            ? $this->validatedBoolean($payload['is_active'], 'is_active')
            : true;

        if ($endsMonth !== null && $endsMonth < $startsMonth) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'ends_month', 'message' => 'must be >= starts_month'],
            ]);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO recurring_expenses (user_id, expense, amount, category, tag_id, card_id, billing_type, billing_day, starts_month, ends_month, is_active)
             VALUES (:user_id, :expense, :amount, :category, :tag_id, :card_id, :billing_type, :billing_day, :starts_month, :ends_month, :is_active)'
        );
        $stmt->execute([
            ':user_id' => $ctx->userId(),
            ':expense' => $expense,
            ':amount' => $amount,
            ':category' => $category,
            ':tag_id' => $tagId,
            ':card_id' => $cardId,
            ':billing_type' => $billingType,
            ':billing_day' => $billingDay,
            ':starts_month' => $startsMonth . '-01',
            ':ends_month' => $endsMonth === null ? null : ($endsMonth . '-01'),
            ':is_active' => $isActive ? 1 : 0,
        ]);

        $recurringExpenseId = (int) $this->pdo->lastInsertId();
        if ($seedTransaction !== null) {
            $seedOccurrenceMonth = substr((string) $seedTransaction['transaction_date'], 0, 7);
            if ($seedOccurrenceMonth < $startsMonth) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'seed_transaction_id', 'message' => 'transaction month must be >= starts_month'],
                ]);
            }
            if ($endsMonth !== null && $seedOccurrenceMonth > $endsMonth) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'seed_transaction_id', 'message' => 'transaction month must be <= ends_month'],
                ]);
            }

            $seedOccurrence = $this->pdo->prepare(
                'INSERT INTO recurring_expense_occurrences (user_id, recurring_expense_id, occurrence_month, due_date, transaction_id)
                 VALUES (:user_id, :recurring_expense_id, :occurrence_month, :due_date, :transaction_id)'
            );
            $seedOccurrence->execute([
                ':user_id' => $ctx->userId(),
                ':recurring_expense_id' => $recurringExpenseId,
                ':occurrence_month' => $seedOccurrenceMonth . '-01',
                ':due_date' => (string) $seedTransaction['transaction_date'],
                ':transaction_id' => (int) $seedTransaction['id'],
            ]);
        }

        if ($isActive && $this->monthApplies($this->recurring->currentMonth(), $startsMonth, $endsMonth)) {
            $this->recurring->ensureGeneratedForMonth($ctx->userId(), $this->recurring->currentMonth());
        }

        return Response::json($this->fetchOne($ctx->userId(), $recurringExpenseId), 201);
    }

    /** @param array{recurring_expense_id:string} $params */
    public function update(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $id = $this->parseEntityId((string) ($params['recurring_expense_id'] ?? ''), 'recurring_expense_id');
        $existing = $this->fetchRaw($ctx->userId(), $id);
        $payload = $request->json();

        $expense = array_key_exists('expense', $payload)
            ? $this->validatedExpense($payload['expense'])
            : (string) $existing['expense'];
        $amount = array_key_exists('amount', $payload)
            ? $this->validatedMoney($payload['amount'], 'amount')
            : $this->fmt((string) $existing['amount']);
        $category = array_key_exists('category', $payload)
            ? $this->validatedCategory($payload['category'])
            : (string) $existing['category'];
        $tagId = array_key_exists('tag_id', $payload)
            ? $this->resolvedTagId($ctx->userId(), $payload['tag_id'])
            : (int) $existing['tag_id'];
        $cardId = array_key_exists('card_id', $payload)
            ? $this->resolvedCardId($ctx->userId(), $payload['card_id'])
            : ($existing['card_id'] === null ? null : (int) $existing['card_id']);
        $billingType = array_key_exists('billing_type', $payload)
            ? $this->validatedBillingType($payload['billing_type'])
            : (string) $existing['billing_type'];
        $billingDay = array_key_exists('billing_day', $payload) || array_key_exists('billing_type', $payload)
            ? $this->validatedBillingDay($payload['billing_day'] ?? null, $billingType)
            : ($existing['billing_day'] === null ? null : (int) $existing['billing_day']);
        $startsMonth = array_key_exists('starts_month', $payload)
            ? $this->validatedMonth($payload['starts_month'], 'starts_month')
            : substr((string) $existing['starts_month'], 0, 7);
        $endsMonth = array_key_exists('ends_month', $payload)
            ? $this->validatedNullableMonth($payload['ends_month'], 'ends_month')
            : ($existing['ends_month'] === null ? null : substr((string) $existing['ends_month'], 0, 7));
        $isActive = array_key_exists('is_active', $payload)
            ? $this->validatedBoolean($payload['is_active'], 'is_active')
            : ((int) $existing['is_active'] === 1);

        if ($endsMonth !== null && $endsMonth < $startsMonth) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'ends_month', 'message' => 'must be >= starts_month'],
            ]);
        }

        $stmt = $this->pdo->prepare(
            'UPDATE recurring_expenses
             SET expense = :expense,
                 amount = :amount,
                 category = :category,
                 tag_id = :tag_id,
                 card_id = :card_id,
                 billing_type = :billing_type,
                 billing_day = :billing_day,
                 starts_month = :starts_month,
                 ends_month = :ends_month,
                 is_active = :is_active,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL'
        );
        $stmt->execute([
            ':expense' => $expense,
            ':amount' => $amount,
            ':category' => $category,
            ':tag_id' => $tagId,
            ':card_id' => $cardId,
            ':billing_type' => $billingType,
            ':billing_day' => $billingDay,
            ':starts_month' => $startsMonth . '-01',
            ':ends_month' => $endsMonth === null ? null : ($endsMonth . '-01'),
            ':is_active' => $isActive ? 1 : 0,
            ':id' => $id,
            ':user_id' => $ctx->userId(),
        ]);

        if ($isActive && $this->monthApplies($this->recurring->currentMonth(), $startsMonth, $endsMonth)) {
            $this->recurring->ensureGeneratedForMonth($ctx->userId(), $this->recurring->currentMonth());
        }

        return Response::json($this->fetchOne($ctx->userId(), $id));
    }

    /** @param array{recurring_expense_id:string} $params */
    public function delete(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $id = $this->parseEntityId((string) ($params['recurring_expense_id'] ?? ''), 'recurring_expense_id');

        $stmt = $this->pdo->prepare(
            'UPDATE recurring_expenses
             SET is_active = 0, deleted_at = UTC_TIMESTAMP(), updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL'
        );
        $stmt->execute([
            ':id' => $id,
            ':user_id' => $ctx->userId(),
        ]);

        if ($stmt->rowCount() === 0) {
            throw new HttpException(404, 'NOT_FOUND', 'Recurring expense not found');
        }

        return Response::noContent();
    }

    /** @return array<string,mixed> */
    private function fetchRaw(int $userId, int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, expense, amount, category, tag_id, card_id, billing_type, billing_day, starts_month, ends_month, is_active
             FROM recurring_expenses
             WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([
            ':id' => $id,
            ':user_id' => $userId,
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException(404, 'NOT_FOUND', 'Recurring expense not found');
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function fetchOne(int $userId, int $id): array
    {
        $sql = <<<'SQL'
SELECT
  re.id,
  re.expense,
  re.amount,
  re.category,
  re.tag_id,
  re.card_id,
  re.billing_type,
  re.billing_day,
  re.starts_month,
  re.ends_month,
  re.is_active,
  re.created_at,
  re.updated_at,
  tg.name AS tag_name,
  tg.icon_key AS tag_icon_key,
  c.name AS card_name
FROM recurring_expenses re
JOIN tags tg ON tg.id = re.tag_id AND tg.user_id = re.user_id
LEFT JOIN cards c ON c.id = re.card_id AND c.user_id = re.user_id
WHERE re.id = :id AND re.user_id = :user_id AND re.deleted_at IS NULL
LIMIT 1
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':user_id' => $userId,
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException(404, 'NOT_FOUND', 'Recurring expense not found');
        }

        $month = $this->recurring->currentMonth();
        $billingType = (string) $row['billing_type'];
        $billingDay = $row['billing_day'] === null ? null : (int) $row['billing_day'];

        return [
            'id' => (string) $row['id'],
            'expense' => (string) $row['expense'],
            'amount' => $this->fmt((string) $row['amount']),
            'category' => (string) $row['category'],
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
            'billing_type' => $billingType,
            'billing_day' => $billingDay,
            'projected_date_for_month' => $this->projectedDateForMonth($month, $billingType, $billingDay),
            'starts_month' => substr((string) $row['starts_month'], 0, 7),
            'ends_month' => $row['ends_month'] === null ? null : substr((string) $row['ends_month'], 0, 7),
            'is_active' => (bool) $row['is_active'],
            'generated_for_month' => false,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private function validatedExpense(mixed $value): string
    {
        $expense = trim((string) $value);
        if ($expense === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'expense', 'message' => 'is required'],
            ]);
        }
        if (mb_strlen($expense) > 160) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'expense', 'message' => 'must be <= 160 characters'],
            ]);
        }

        return $expense;
    }

    private function validatedMoney(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^\d+(\.\d{2})$/', $value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a decimal string like 123.45'],
            ]);
        }
        if ((float) $value <= 0.0) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be greater than 0'],
            ]);
        }

        return $this->fmt($value);
    }

    private function validatedCategory(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::ALLOWED_CATEGORIES, true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'category', 'message' => 'must be one of needs,wants,savings_debts'],
            ]);
        }

        return $value;
    }

    private function validatedBillingType(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::ALLOWED_BILLING_TYPES, true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'billing_type', 'message' => 'must be one of day_of_month,last_day'],
            ]);
        }

        return $value;
    }

    private function validatedBillingDay(mixed $value, string $billingType): ?int
    {
        if ($billingType === 'last_day') {
            return null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (!is_int($value) || $value < 1 || $value > 31) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'billing_day', 'message' => 'must be an integer between 1 and 31'],
            ]);
        }

        return $value;
    }

    private function validatedMonth(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be YYYY-MM'],
            ]);
        }

        $month = trim($value);
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

    private function validatedNullableMonth(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->validatedMonth($value, $field);
    }

    private function validatedBoolean(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be boolean'],
            ]);
        }

        return $value;
    }

    private function resolvedTagId(int $userId, mixed $value): int
    {
        if (!is_string($value) || !ctype_digit($value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'tag_id', 'message' => 'must be a numeric id'],
            ]);
        }
        $tagId = (int) $value;

        $stmt = $this->pdo->prepare(
            'SELECT id FROM tags WHERE id = :id AND user_id = :user_id AND is_active = 1 AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([
            ':id' => $tagId,
            ':user_id' => $userId,
        ]);
        if (!$stmt->fetch()) {
            throw new HttpException(404, 'NOT_FOUND', 'Tag not found');
        }

        return $tagId;
    }

    private function resolvedCardId(int $userId, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || !ctype_digit($value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'card_id', 'message' => 'must be a numeric id or null'],
            ]);
        }
        $cardId = (int) $value;

        $stmt = $this->pdo->prepare(
            'SELECT id FROM cards WHERE id = :id AND user_id = :user_id AND is_active = 1 AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([
            ':id' => $cardId,
            ':user_id' => $userId,
        ]);
        if (!$stmt->fetch()) {
            throw new HttpException(404, 'NOT_FOUND', 'Card not found');
        }

        return $cardId;
    }

    /** @return array{id:mixed,transaction_date:mixed} */
    private function resolvedSeedTransaction(int $userId, mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || !ctype_digit($value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'seed_transaction_id', 'message' => 'must be a numeric id or null'],
            ]);
        }

        $transactionId = (int) $value;
        $stmt = $this->pdo->prepare(
            'SELECT id, transaction_date
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
        if (!$row) {
            throw new HttpException(404, 'NOT_FOUND', 'Seed transaction not found');
        }

        return [
            'id' => $row['id'],
            'transaction_date' => $row['transaction_date'],
        ];
    }

    private function parseEntityId(string $raw, string $field): int
    {
        if ($raw === '' || !ctype_digit($raw)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a numeric id'],
            ]);
        }

        return (int) $raw;
    }

    private function projectedDateForMonth(string $month, string $billingType, ?int $billingDay): string
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m', $month, new DateTimeZone('UTC'));
        if (!$dt) {
            return $month . '-01';
        }

        $daysInMonth = (int) $dt->modify('last day of this month')->format('d');
        $day = $billingType === 'last_day'
            ? $daysInMonth
            : min(max((int) ($billingDay ?? 1), 1), $daysInMonth);

        return sprintf('%s-%02d', $month, $day);
    }

    private function monthApplies(string $targetMonth, string $startsMonth, ?string $endsMonth): bool
    {
        if ($startsMonth > $targetMonth) {
            return false;
        }

        return $endsMonth === null || $endsMonth >= $targetMonth;
    }

    private function fmt(string $decimal): string
    {
        return number_format((float) $decimal, 2, '.', '');
    }
}
