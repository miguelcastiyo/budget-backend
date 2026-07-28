<?php

declare(strict_types=1);

namespace App\Recurring;

use App\Http\HttpException;
use App\Support\Str;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;

final class RecurringExpenseService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?DateTimeImmutable $clockNow = null
    )
    {
    }

    public function currentMonth(): string
    {
        return ($this->clockNow ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m');
    }

    public function newSeriesId(): string
    {
        return Str::randomId('rser');
    }

    public function normalizeMonth(string $month): string
    {
        $month = trim($month);
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

        return $month;
    }

    public function ensureGeneratedForDateRange(int $userId, ?string $dateFrom, ?string $dateTo): void
    {
        if ($dateFrom === null || $dateTo === null) {
            $this->ensureGeneratedForMonth($userId, $this->currentMonth());
            return;
        }

        $start = DateTimeImmutable::createFromFormat('Y-m-d', $dateFrom, new DateTimeZone('UTC'));
        $end = DateTimeImmutable::createFromFormat('Y-m-d', $dateTo, new DateTimeZone('UTC'));
        if (!$start || !$end) {
            $this->ensureGeneratedForMonth($userId, $this->currentMonth());
            return;
        }

        $cursor = $start->modify('first day of this month');
        $lastMonth = $end->modify('first day of this month');
        if ($cursor > $lastMonth) {
            [$cursor, $lastMonth] = [$lastMonth, $cursor];
        }

        $maxMonths = 24;
        $processed = 0;
        while ($cursor <= $lastMonth && $processed < $maxMonths) {
            $this->ensureGeneratedForMonth($userId, $cursor->format('Y-m'));
            $cursor = $cursor->modify('+1 month');
            $processed++;
        }
    }

    public function ensureGeneratedForMonth(int $userId, string $month): void
    {
        $month = $this->normalizeMonth($month);
        if ($month > $this->currentMonth()) {
            return;
        }
        [$instanceMonth, $daysInMonth] = $this->monthStartAndDays($month);

        $stmt = $this->pdo->prepare(
            "SELECT id, expense, amount, category, tag_id, card_id, billing_type, billing_day
             FROM recurring_expenses
             WHERE user_id = :user_id
               AND is_active = 1
               AND deleted_at IS NULL
               AND starts_month <= :instance_month_start
               AND (ends_month IS NULL OR ends_month >= :instance_month_end)
             ORDER BY id ASC"
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':instance_month_start' => $instanceMonth,
            ':instance_month_end' => $instanceMonth,
        ]);

        $existingOccurrences = $this->existingOccurrenceMap($userId, $instanceMonth);
        $insertOccurrence = $this->pdo->prepare(
            'INSERT IGNORE INTO recurring_expense_occurrences (user_id, recurring_expense_id, occurrence_month, due_date) VALUES (:user_id, :recurring_expense_id, :occurrence_month, :due_date)'
        );
        $insertTransaction = $this->pdo->prepare(
            "INSERT INTO transactions (user_id, transaction_date, expense, amount, category, tag_id, card_id, notes, source)
             VALUES (:user_id, :transaction_date, :expense, :amount, :category, :tag_id, :card_id, NULL, 'manual')"
        );
        $linkOccurrence = $this->pdo->prepare(
            'UPDATE recurring_expense_occurrences SET transaction_id = :transaction_id WHERE id = :id AND user_id = :user_id'
        );

        foreach ($stmt->fetchAll() as $row) {
            $recurringExpenseId = (int) $row['id'];
            if (isset($existingOccurrences[$recurringExpenseId])) {
                continue;
            }

            $billingType = (string) $row['billing_type'];
            $billingDay = $billingType === 'last_day'
                ? $daysInMonth
                : min(max((int) ($row['billing_day'] ?? 1), 1), $daysInMonth);
            $dueDate = sprintf('%s-%02d', $month, $billingDay);

            try {
                $ownsTransaction = !$this->pdo->inTransaction();
                if ($ownsTransaction) {
                    $this->pdo->beginTransaction();
                }

                $insertOccurrence->execute([
                    ':user_id' => $userId,
                    ':recurring_expense_id' => $recurringExpenseId,
                    ':occurrence_month' => $instanceMonth,
                    ':due_date' => $dueDate,
                ]);

                if ($insertOccurrence->rowCount() === 0) {
                    if ($ownsTransaction) {
                        $this->pdo->rollBack();
                    }
                    continue;
                }

                $occurrenceId = (int) $this->pdo->lastInsertId();

                $insertTransaction->execute([
                    ':user_id' => $userId,
                    ':transaction_date' => $dueDate,
                    ':expense' => (string) $row['expense'],
                    ':amount' => $this->fmt((string) $row['amount']),
                    ':category' => (string) $row['category'],
                    ':tag_id' => (int) $row['tag_id'],
                    ':card_id' => $row['card_id'] === null ? null : (int) $row['card_id'],
                ]);

                $transactionId = (int) $this->pdo->lastInsertId();
                $linkOccurrence->execute([
                    ':transaction_id' => $transactionId,
                    ':id' => $occurrenceId,
                    ':user_id' => $userId,
                ]);

                if ($ownsTransaction) {
                    (new \App\Privacy\FinancialRevisionService($this->pdo))->increment($userId);
                    $this->pdo->commit();
                }
            } catch (PDOException $e) {
                if (($ownsTransaction ?? false) && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $e;
            }
        }
    }

    /**
     * @param array{
     *   amount?:string,
     *   category?:string,
     *   tag_id?:int,
     *   card_id?:int|null,
     *   billing_type?:string,
     *   billing_day?:int|null
     * } $changes
     * @return array{series_id:string,ended_rule_id:int,new_rule_id:int}
     */
    public function scheduleChange(
        int $userId,
        int $recurringExpenseId,
        string $effectiveMonth,
        array $changes,
        string $generatedTransactionAction = 'reject'
    ): array {
        $effectiveMonth = $this->normalizeMonth($effectiveMonth);

        if ($generatedTransactionAction !== 'reject') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'generated_transaction_action', 'message' => 'update_linked_transaction is not supported yet'],
            ]);
        }

        try {
            $this->pdo->beginTransaction();

            $sourceStmt = $this->pdo->prepare(
                'SELECT id, series_id, user_id, expense, amount, category, tag_id, card_id, billing_type, billing_day, starts_month, ends_month, is_active, deleted_at
                 FROM recurring_expenses
                 WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL
                 LIMIT 1
                 FOR UPDATE'
            );
            $sourceStmt->execute([
                ':id' => $recurringExpenseId,
                ':user_id' => $userId,
            ]);
            $source = $sourceStmt->fetch();

            if (!$source) {
                throw new HttpException(404, 'NOT_FOUND', 'Recurring expense not found');
            }

            if ((int) $source['is_active'] !== 1) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'recurring_expense_id', 'message' => 'schedule changes are only supported for active recurring expenses'],
                ]);
            }

            $sourceStartsMonth = substr((string) $source['starts_month'], 0, 7);
            $sourceEndsMonth = $source['ends_month'] === null ? null : substr((string) $source['ends_month'], 0, 7);

            if ($effectiveMonth <= $sourceStartsMonth) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'effective_month', 'message' => 'must be after starts_month'],
                ]);
            }

            if ($sourceEndsMonth !== null && $effectiveMonth > $sourceEndsMonth) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'effective_month', 'message' => 'must be <= ends_month'],
                ]);
            }

            $seriesId = (string) $source['series_id'];

            $seriesLock = $this->pdo->prepare(
                'SELECT id
                 FROM recurring_expenses
                 WHERE user_id = :user_id
                   AND series_id = :series_id
                   AND deleted_at IS NULL
                 ORDER BY starts_month ASC, id ASC
                 FOR UPDATE'
            );
            $seriesLock->execute([
                ':user_id' => $userId,
                ':series_id' => $seriesId,
            ]);
            $seriesLock->fetchAll();

            $occurrenceStmt = $this->pdo->prepare(
                'SELECT id
                 FROM recurring_expense_occurrences
                 WHERE user_id = :user_id
                   AND recurring_expense_id = :recurring_expense_id
                   AND occurrence_month = :occurrence_month
                 LIMIT 1
                 FOR UPDATE'
            );
            $occurrenceStmt->execute([
                ':user_id' => $userId,
                ':recurring_expense_id' => $recurringExpenseId,
                ':occurrence_month' => $effectiveMonth . '-01',
            ]);
            if ($occurrenceStmt->fetch()) {
                throw new HttpException(409, 'CONFLICT', 'This recurring expense has already generated a transaction for the effective month.', [
                    ['field' => 'effective_month', 'message' => 'Update the generated transaction manually or retry with generated_transaction_action=update_linked_transaction when supported.'],
                ]);
            }

            $newRule = [
                'expense' => (string) $source['expense'],
                'amount' => $this->fmt((string) $source['amount']),
                'category' => (string) $source['category'],
                'tag_id' => (int) $source['tag_id'],
                'card_id' => $source['card_id'] === null ? null : (int) $source['card_id'],
                'billing_type' => (string) $source['billing_type'],
                'billing_day' => $source['billing_day'] === null ? null : (int) $source['billing_day'],
            ];

            foreach ($changes as $field => $value) {
                $newRule[$field] = $value;
            }

            if (
                $newRule['expense'] === (string) $source['expense']
                && $newRule['amount'] === $this->fmt((string) $source['amount'])
                && $newRule['category'] === (string) $source['category']
                && $newRule['tag_id'] === (int) $source['tag_id']
                && $newRule['card_id'] === ($source['card_id'] === null ? null : (int) $source['card_id'])
                && $newRule['billing_type'] === (string) $source['billing_type']
                && $newRule['billing_day'] === ($source['billing_day'] === null ? null : (int) $source['billing_day'])
            ) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'amount', 'message' => 'must change at least one recurring field'],
                ]);
            }

            $endedMonth = $this->previousMonth($effectiveMonth);

            $insertStmt = $this->pdo->prepare(
                'INSERT INTO recurring_expenses (series_id, user_id, expense, amount, category, tag_id, card_id, billing_type, billing_day, starts_month, ends_month, is_active)
                 VALUES (:series_id, :user_id, :expense, :amount, :category, :tag_id, :card_id, :billing_type, :billing_day, :starts_month, :ends_month, 1)'
            );
            $insertStmt->execute([
                ':series_id' => $seriesId,
                ':user_id' => $userId,
                ':expense' => $newRule['expense'],
                ':amount' => $newRule['amount'],
                ':category' => $newRule['category'],
                ':tag_id' => $newRule['tag_id'],
                ':card_id' => $newRule['card_id'],
                ':billing_type' => $newRule['billing_type'],
                ':billing_day' => $newRule['billing_day'],
                ':starts_month' => $effectiveMonth . '-01',
                ':ends_month' => $sourceEndsMonth === null ? null : ($sourceEndsMonth . '-01'),
            ]);
            $newRuleId = (int) $this->pdo->lastInsertId();

            $updateStmt = $this->pdo->prepare(
                'UPDATE recurring_expenses
                 SET ends_month = :ends_month,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND user_id = :user_id'
            );
            $updateStmt->execute([
                ':ends_month' => $endedMonth . '-01',
                ':id' => $recurringExpenseId,
                ':user_id' => $userId,
            ]);

            $this->assertNoSeriesOverlap($userId, $seriesId);

            (new \App\Privacy\FinancialRevisionService($this->pdo))->increment($userId);

            $this->pdo->commit();

            return [
                'series_id' => $seriesId,
                'ended_rule_id' => $recurringExpenseId,
                'new_rule_id' => $newRuleId,
            ];
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        } catch (HttpException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function recurringExpenseSeriesId(int $userId, int $recurringExpenseId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT series_id
             FROM recurring_expenses
             WHERE id = :id
               AND user_id = :user_id
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([
            ':id' => $recurringExpenseId,
            ':user_id' => $userId,
        ]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new HttpException(404, 'NOT_FOUND', 'Recurring expense not found');
        }

        return (string) $row['series_id'];
    }

    public function previousMonth(string $month): string
    {
        $month = $this->normalizeMonth($month);
        $dt = DateTimeImmutable::createFromFormat('Y-m', $month, new DateTimeZone('UTC'));
        if (!$dt) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'month', 'message' => 'must be a valid month'],
            ]);
        }

        return $dt->modify('-1 month')->format('Y-m');
    }

    public function assertNoSeriesOverlap(int $userId, string $seriesId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, starts_month, ends_month
             FROM recurring_expenses
             WHERE user_id = :user_id
               AND series_id = :series_id
               AND deleted_at IS NULL
             ORDER BY starts_month ASC, id ASC'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':series_id' => $seriesId,
        ]);

        $items = $stmt->fetchAll();
        for ($index = 0, $count = count($items); $index < $count; $index++) {
            $current = $items[$index];
            $currentStart = substr((string) $current['starts_month'], 0, 7);
            $currentEnd = $current['ends_month'] === null ? null : substr((string) $current['ends_month'], 0, 7);

            for ($compareIndex = $index + 1; $compareIndex < $count; $compareIndex++) {
                $candidate = $items[$compareIndex];
                $candidateStart = substr((string) $candidate['starts_month'], 0, 7);
                $candidateEnd = $candidate['ends_month'] === null ? null : substr((string) $candidate['ends_month'], 0, 7);

                if (!$this->monthRangesOverlap($currentStart, $currentEnd, $candidateStart, $candidateEnd)) {
                    continue;
                }

                throw new HttpException(409, 'CONFLICT', 'Recurring series contains overlapping month windows.', [
                    ['field' => 'effective_month', 'message' => 'Recurring series versions must not overlap.'],
                ]);
            }
        }
    }

    /** @return array{0:string,1:int} */
    private function monthStartAndDays(string $month): array
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m', $month, new DateTimeZone('UTC'));
        if (!$dt) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'month', 'message' => 'must be a valid month'],
            ]);
        }

        $start = $dt->setDate((int) $dt->format('Y'), (int) $dt->format('m'), 1);
        $daysInMonth = (int) $start->modify('last day of this month')->format('d');

        return [$start->format('Y-m-d'), $daysInMonth];
    }

    private function monthRangesOverlap(string $firstStart, ?string $firstEnd, string $secondStart, ?string $secondEnd): bool
    {
        $firstUpperBound = $firstEnd ?? '9999-12';
        $secondUpperBound = $secondEnd ?? '9999-12';

        return $firstStart <= $secondUpperBound && $secondStart <= $firstUpperBound;
    }

    private function fmt(string $decimal): string
    {
        return number_format((float) $decimal, 2, '.', '');
    }

    /** @return array<int, true> */
    private function existingOccurrenceMap(int $userId, string $instanceMonth): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT recurring_expense_id FROM recurring_expense_occurrences WHERE user_id = :user_id AND occurrence_month = :occurrence_month'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':occurrence_month' => $instanceMonth,
        ]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['recurring_expense_id']] = true;
        }

        return $map;
    }
}
