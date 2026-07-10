<?php

declare(strict_types=1);

namespace App\Funds;

use App\Http\HttpException;
use App\Support\Str;
use PDO;
use Throwable;

final class FundTransactionIntegrationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly FundRepository $repository
    ) {
    }

    /** @param array<string,mixed> $fund */
    public function createTransactionLinkedContribution(
        int $userId,
        array $fund,
        string $entryDate,
        string $amount,
        string $expense,
        int $tagId,
        ?int $cardId,
        ?string $transactionNotes,
        ?string $entryNote
    ): string {
        if ((string) $fund['status'] !== 'active') {
            throw new HttpException(409, 'CONFLICT', 'Archived funds cannot receive new entries');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO transactions (
                   user_id, transaction_date, expense, amount, category, tag_id, card_id, is_split, notes, source
                 ) VALUES (
                   :user_id, :transaction_date, :expense, :amount, 'savings', :tag_id, :card_id, 0, :notes, 'manual'
                 )"
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':transaction_date' => $entryDate,
                ':expense' => $expense,
                ':amount' => $amount,
                ':tag_id' => $tagId,
                ':card_id' => $cardId,
                ':notes' => $transactionNotes,
            ]);

            $transactionId = (int) $this->pdo->lastInsertId();
            $entryPublicId = $this->repository->insertEntry(
                $userId,
                (int) $fund['id'],
                $entryDate,
                'contribution',
                'in',
                $amount,
                'transaction',
                $transactionId,
                null,
                null,
                $entryNote ?? $transactionNotes
            );
            $this->pdo->commit();

            return $entryPublicId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $fund */
    public function linkExistingTransaction(array $fund, int $userId, int $transactionId, string $amount, ?string $entryNote): string
    {
        if ((string) $fund['status'] !== 'active') {
            throw new HttpException(409, 'CONFLICT', 'Archived funds cannot receive new entries');
        }

        $transaction = $this->repository->findActiveTransactionForLink($userId, $transactionId);
        if ($transaction === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Transaction not found');
        }
        if ((string) $transaction['category'] !== 'savings') {
            throw new HttpException(409, 'CONFLICT', 'Linked contribution transactions must use the savings category');
        }
        if ($this->fmt((string) $transaction['amount']) !== $this->fmt($amount)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'amount', 'message' => 'must match the linked transaction amount'],
            ]);
        }
        if ($this->repository->findActiveEntryByTransactionId($userId, $transactionId) !== null) {
            throw new HttpException(409, 'CONFLICT', 'Transaction already has an active fund entry');
        }

        return $this->repository->insertEntry(
            $userId,
            (int) $fund['id'],
            (string) $transaction['transaction_date'],
            'contribution',
            'in',
            $amount,
            'transaction',
            $transactionId,
            null,
            null,
            $entryNote ?? ($transaction['notes'] === null ? null : (string) $transaction['notes'])
        );
    }

    /** @return array<string,mixed>|null */
    public function findActiveEntryByTransactionId(int $userId, int $transactionId): ?array
    {
        return $this->repository->findActiveEntryByTransactionId($userId, $transactionId);
    }

    /** @param array<string,mixed> $existingTransaction
     *  @param array<string,mixed> $activeEntry
     */
    public function syncLinkedTransactionUpdate(int $userId, array $existingTransaction, array $activeEntry, string $date, string $amount, string $category, ?string $notes): void
    {
        if ($category !== 'savings') {
            throw new HttpException(409, 'CONFLICT', 'Linked contribution transactions must remain in the savings category');
        }

        $entryNote = (string) ($activeEntry['note'] ?? '') === (string) ($existingTransaction['notes'] ?? '')
            ? $notes
            : ($activeEntry['note'] === null ? null : (string) $activeEntry['note']);

        $this->repository->syncEntryFromTransaction((int) $activeEntry['id'], $userId, $date, $amount, $entryNote);
    }

    public function voidLinkedTransactionDelete(int $userId, int $transactionId, string $voidedAt): void
    {
        $activeEntry = $this->repository->findActiveEntryByTransactionId($userId, $transactionId);
        if ($activeEntry === null) {
            return;
        }

        $this->repository->voidEntry((int) $activeEntry['id'], $userId, 'transaction_deleted', $voidedAt);
    }

    private function fmt(string $decimal): string
    {
        return number_format((float) $decimal, 2, '.', '');
    }
}
