<?php

declare(strict_types=1);

namespace App\Recurring;

use App\Http\HttpException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;

final class RecurringExpenseService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function currentMonth(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m');
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
            "INSERT INTO transactions (user_id, transaction_date, expense, amount, category, tag_id, card_id, source)
             VALUES (:user_id, :transaction_date, :expense, :amount, :category, :tag_id, :card_id, 'manual')"
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
                $this->pdo->beginTransaction();

                $insertOccurrence->execute([
                    ':user_id' => $userId,
                    ':recurring_expense_id' => $recurringExpenseId,
                    ':occurrence_month' => $instanceMonth,
                    ':due_date' => $dueDate,
                ]);

                if ($insertOccurrence->rowCount() === 0) {
                    $this->pdo->rollBack();
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

                $this->pdo->commit();
            } catch (PDOException $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $e;
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
