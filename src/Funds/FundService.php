<?php

declare(strict_types=1);

namespace App\Funds;

use App\Http\HttpException;
use PDOException;

final class FundService
{
    private const ALLOWED_FUND_TYPES = ['goal', 'emergency', 'buffer', 'debt', 'investment', 'other'];
    private const ALLOWED_ENTRY_TYPES = ['contribution', 'withdrawal', 'adjustment', 'starting_balance'];
    private const ALLOWED_SOURCE_TYPES = ['manual', 'transaction', 'month_closeout', 'starting_balance', 'correction'];
    private const EDITABLE_SOURCE_TYPES = ['manual', 'starting_balance', 'correction'];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly FundRepository $repository,
        private readonly FundBalanceService $balanceService,
        private readonly FundTransactionIntegrationService $transactionIntegration
    ) {
    }

    /** @return array<string,mixed> */
    public function listFunds(int $userId, array $query): array
    {
        $status = $this->validatedStatusFilter($query['status'] ?? null);
        $includeSummary = $this->validatedBoolean($query['include_entries_summary'] ?? true, 'include_entries_summary');
        $funds = $this->balanceService->summarizeFunds($userId, $this->repository->listFunds($userId, $status));

        if (!$includeSummary) {
            return ['items' => $funds];
        }

        $items = [];
        foreach ($funds as $fund) {
            $raw = $this->requireFund($userId, (string) $fund['id']);
            $items[] = $fund + [
                'entries_count' => $this->repository->countEntries($userId, (int) $raw['id'], []),
            ];
        }

        return ['items' => $items];
    }

    /** @param array<string,mixed> $payload */
    public function createFund(int $userId, array $payload): array
    {
        $name = $this->validatedName($payload['name'] ?? null);
        $fundType = $this->validatedFundType($payload['fund_type'] ?? 'goal');
        $goalAmount = $this->nullableMoney($payload['goal_amount'] ?? null, 'goal_amount');
        $targetMonthStart = $this->nullableMonthStart($payload['target_month'] ?? null, 'target_month');
        $this->validateTargetMonthRequiresGoal($goalAmount, $targetMonthStart);
        $notes = $this->nullableString($payload['notes'] ?? null, 'notes');
        $startingBalance = $this->nullableNonNegativeMoney($payload['starting_balance'] ?? null, 'starting_balance');
        $sortOrder = array_key_exists('sort_order', $payload) ? $this->validatedSortOrder($payload['sort_order']) : 0;

        if ($this->repository->activeNameExists($userId, $name)) {
            throw new HttpException(409, 'CONFLICT', 'Fund already exists');
        }

        try {
            $this->pdo->beginTransaction();
            $fund = $this->repository->createFund($userId, $name, $fundType, $goalAmount, $targetMonthStart, $notes, $sortOrder);
            if ($startingBalance !== null && (float) $startingBalance > 0.0) {
                $this->repository->insertEntry(
                    $userId,
                    (int) $fund['id'],
                    gmdate('Y-m-d'),
                    'starting_balance',
                    'in',
                    $startingBalance,
                    'starting_balance',
                    null,
                    null,
                    null,
                    null
                );
            }
            (new \App\Privacy\FinancialRevisionService($this->pdo))->increment($userId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof PDOException && ($e->errorInfo[0] ?? '') === '23000') {
                throw new HttpException(409, 'CONFLICT', 'Fund already exists');
            }
            throw $e;
        }

        return $this->getFund($userId, (string) $fund['fund_id']);
    }

    public function getFund(int $userId, string $fundPublicId): array
    {
        $fund = $this->requireFund($userId, $fundPublicId);
        $summary = $this->balanceService->summarizeFund($userId, $fund);

        return $summary + [
            'source_breakdown' => $this->repository->sourceBreakdown($userId, (int) $fund['id']),
            'entries_count' => $this->repository->countEntries($userId, (int) $fund['id'], []),
            'recent_entries' => array_map(fn(array $row): array => $this->entryResponse($row, (string) $fund['fund_id']), $this->repository->recentEntries($userId, (int) $fund['id'], 10)),
        ];
    }

    /** @param array<string,mixed> $payload */
    public function updateFund(int $userId, string $fundPublicId, array $payload): array
    {
        $fund = $this->requireFund($userId, $fundPublicId);
        $name = array_key_exists('name', $payload) ? $this->validatedName($payload['name']) : (string) $fund['name'];
        $fundType = array_key_exists('fund_type', $payload) ? $this->validatedFundType($payload['fund_type']) : (string) $fund['fund_type'];
        $goalAmount = array_key_exists('goal_amount', $payload) ? $this->nullableMoney($payload['goal_amount'], 'goal_amount') : ($fund['goal_amount'] === null ? null : $this->fmt((string) $fund['goal_amount']));
        $targetMonthStart = array_key_exists('target_month', $payload) ? $this->nullableMonthStart($payload['target_month'], 'target_month') : ($fund['target_month'] === null ? null : (string) $fund['target_month']);
        if (array_key_exists('goal_amount', $payload) && $goalAmount === null && !array_key_exists('target_month', $payload)) {
            $targetMonthStart = null;
        }
        $this->validateTargetMonthRequiresGoal($goalAmount, $targetMonthStart);
        $notes = array_key_exists('notes', $payload) ? $this->nullableString($payload['notes'], 'notes') : ($fund['notes'] === null ? null : (string) $fund['notes']);
        $sortOrder = array_key_exists('sort_order', $payload) ? $this->validatedSortOrder($payload['sort_order']) : (int) $fund['sort_order'];

        if ($this->repository->activeNameExists($userId, $name, (int) $fund['id'])) {
            throw new HttpException(409, 'CONFLICT', 'Fund already exists');
        }

        $this->pdo->beginTransaction();
        try {
            $this->repository->updateFund((int) $fund['id'], $userId, $name, $fundType, $goalAmount, $targetMonthStart, $notes, $sortOrder);
            (new \App\Privacy\FinancialRevisionService($this->pdo))->increment($userId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->getFund($userId, $fundPublicId);
    }

    public function archiveFund(int $userId, string $fundPublicId): array
    {
        $fund = $this->requireFund($userId, $fundPublicId);
        $this->pdo->beginTransaction();
        try {
            $this->repository->archiveFund((int) $fund['id'], $userId, $this->nowUtc());
            (new \App\Privacy\FinancialRevisionService($this->pdo))->increment($userId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $this->getFund($userId, $fundPublicId);
    }

    public function restoreFund(int $userId, string $fundPublicId): array
    {
        $fund = $this->requireFund($userId, $fundPublicId);
        if ($this->repository->activeNameExists($userId, (string) $fund['name'], (int) $fund['id'])) {
            throw new HttpException(409, 'CONFLICT', 'Another active fund already uses this name');
        }
        $this->pdo->beginTransaction();
        try {
            $this->repository->restoreFund((int) $fund['id'], $userId);
            (new \App\Privacy\FinancialRevisionService($this->pdo))->increment($userId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $this->getFund($userId, $fundPublicId);
    }

    public function listEntries(int $userId, string $fundPublicId, array $query): array
    {
        $fund = $this->requireFund($userId, $fundPublicId);
        $filters = [
            'source_type' => $this->optionalEnum($query['source_type'] ?? null, self::ALLOWED_SOURCE_TYPES, 'source_type'),
            'entry_type' => $this->optionalEnum($query['entry_type'] ?? null, self::ALLOWED_ENTRY_TYPES, 'entry_type'),
            'date_from' => $this->optionalDate($query['date_from'] ?? null, 'date_from'),
            'date_to' => $this->optionalDate($query['date_to'] ?? null, 'date_to'),
        ];
        if ($filters['date_from'] !== null && $filters['date_to'] !== null && $filters['date_from'] > $filters['date_to']) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'date_range', 'message' => 'date_from must be <= date_to'],
            ]);
        }
        $page = max(1, (int) ($query['page'] ?? 1));
        $pageSize = min(max((int) ($query['page_size'] ?? 50), 1), 200);

        $rows = $this->repository->listEntries($userId, (int) $fund['id'], $filters, $page, $pageSize);
        return [
            'items' => array_map(fn(array $row): array => $this->entryResponse($row, $fundPublicId), $rows),
            'page' => $page,
            'page_size' => $pageSize,
            'total_items' => $this->repository->countEntries($userId, (int) $fund['id'], $filters),
        ];
    }

    /** @param array<string,mixed> $payload */
    public function createEntry(int $userId, string $fundPublicId, array $payload): array
    {
        $fund = $this->requireFund($userId, $fundPublicId);
        $entryType = $this->validatedEntryType($payload['entry_type'] ?? null);
        $direction = $this->validatedDirection($payload['direction'] ?? null);
        $amount = $this->validatedMoney($payload['amount'] ?? null, 'amount');
        $sourceType = $this->validatedSourceType($payload['source_type'] ?? 'manual');
        $entryDate = array_key_exists('entry_date', $payload) ? $this->validatedDate($payload['entry_date'], 'entry_date') : gmdate('Y-m-d');
        $note = $this->nullableString($payload['note'] ?? null, 'note');
        $budgetTracking = trim((string) ($payload['budget_tracking'] ?? 'fund_only'));

        if ((string) $fund['status'] !== 'active') {
            throw new HttpException(409, 'CONFLICT', 'Archived funds cannot receive new entries');
        }

        if ($budgetTracking === 'create_transaction') {
            $transaction = is_array($payload['transaction'] ?? null) ? $payload['transaction'] : null;
            if ($entryType !== 'contribution' || $direction !== 'in') {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'entry_type', 'message' => 'transaction-linked fund creation currently supports incoming contributions only'],
                ]);
            }
            if ($transaction === null) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'transaction', 'message' => 'is required'],
                ]);
            }
            $expense = $this->validatedShortString($transaction['expense'] ?? null, 'transaction.expense', 160);
            $tagId = $this->validatedExistingNumericId($transaction['tag_id'] ?? null, 'transaction.tag_id', 'tags', $userId);
            $cardId = array_key_exists('card_id', $transaction) && $transaction['card_id'] !== null
                ? $this->validatedExistingNumericId($transaction['card_id'], 'transaction.card_id', 'cards', $userId)
                : null;
            $transactionNotes = $this->nullableString($transaction['notes'] ?? null, 'transaction.notes');

            $entryPublicId = $this->transactionIntegration->createTransactionLinkedContribution(
                $userId,
                $fund,
                $entryDate,
                $amount,
                $expense,
                $tagId,
                $cardId,
                $transactionNotes,
                $note
            );

            return $this->entryResponse($this->requireEntry($userId, (int) $fund['id'], $entryPublicId), $fundPublicId);
        }

        if ($budgetTracking === 'link_existing_transaction') {
            if ($entryType !== 'contribution' || $direction !== 'in') {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'entry_type', 'message' => 'linked transactions currently support incoming contributions only'],
                ]);
            }
            $transactionId = $this->validatedNumericId($payload['transaction_id'] ?? null, 'transaction_id');
            $entryPublicId = $this->transactionIntegration->linkExistingTransaction($fund, $userId, $transactionId, $amount, $note);
            return $this->entryResponse($this->requireEntry($userId, (int) $fund['id'], $entryPublicId), $fundPublicId);
        }

        if ($budgetTracking !== 'fund_only') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'budget_tracking', 'message' => 'must be fund_only, create_transaction, or link_existing_transaction'],
            ]);
        }

        $this->pdo->beginTransaction();
        try {
            $entryPublicId = $this->repository->insertEntry(
                $userId, (int) $fund['id'], $entryDate, $entryType, $direction, $amount,
                $sourceType, null, null, null, $note
            );
            (new \App\Privacy\FinancialRevisionService($this->pdo))->increment($userId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->entryResponse($this->requireEntry($userId, (int) $fund['id'], $entryPublicId), $fundPublicId);
    }

    /** @param array<string,mixed> $payload */
    public function updateEntry(int $userId, string $fundPublicId, string $entryPublicId, array $payload): array
    {
        $fund = $this->requireFund($userId, $fundPublicId);
        $entry = $this->requireEntry($userId, (int) $fund['id'], $entryPublicId);
        $this->assertEntryEditable($entry);

        $entryType = array_key_exists('entry_type', $payload) ? $this->validatedEntryType($payload['entry_type']) : (string) $entry['entry_type'];
        $direction = array_key_exists('direction', $payload) ? $this->validatedDirection($payload['direction']) : (string) $entry['direction'];
        $amount = array_key_exists('amount', $payload) ? $this->validatedMoney($payload['amount'], 'amount') : $this->fmt((string) $entry['amount']);
        $entryDate = array_key_exists('entry_date', $payload) ? $this->validatedDate($payload['entry_date'], 'entry_date') : (string) $entry['entry_date'];
        $note = array_key_exists('note', $payload) ? $this->nullableString($payload['note'], 'note') : ($entry['note'] === null ? null : (string) $entry['note']);

        $this->pdo->beginTransaction();
        try {
            $this->repository->updateEditableEntry((int) $entry['id'], $userId, $entryDate, $entryType, $direction, $amount, $note);
            (new \App\Privacy\FinancialRevisionService($this->pdo))->increment($userId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->entryResponse($this->requireEntry($userId, (int) $fund['id'], $entryPublicId), $fundPublicId);
    }

    public function deleteEntry(int $userId, string $fundPublicId, string $entryPublicId): void
    {
        $fund = $this->requireFund($userId, $fundPublicId);
        $entry = $this->requireEntry($userId, (int) $fund['id'], $entryPublicId);
        $this->assertEntryEditable($entry);
        $this->pdo->beginTransaction();
        try {
            $this->repository->softDeleteEditableEntry((int) $entry['id'], $userId, $this->nowUtc());
            (new \App\Privacy\FinancialRevisionService($this->pdo))->increment($userId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function closeoutSummary(int $userId, int $year): array
    {
        if ($year < 2000 || $year > 9999) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'year', 'message' => 'must be a valid year'],
            ]);
        }

        $summary = $this->repository->closeoutSummary($userId, $year);
        $items = [];
        foreach ($summary['fund_rows'] as $row) {
            $fund = $this->requireFund($userId, (string) $row['fund_id']);
            $decorated = $this->balanceService->summarizeFund($userId, $fund);
            $items[] = [
                'fund' => [
                    'id' => $decorated['id'],
                    'name' => $decorated['name'],
                    'fund_type' => $decorated['fund_type'],
                    'goal_amount' => $decorated['goal_amount'],
                    'current_balance' => $decorated['current_balance'],
                    'percent_funded' => $decorated['percent_funded'],
                ],
                'closeout_contributed' => $this->fmt((string) $row['closeout_contributed']),
                'closeout_count' => (int) $row['closeout_count'],
            ];
        }

        return [
            'year' => $year,
            'total_closeout_contributed' => $summary['total_closeout_contributed'],
            'funds' => $items,
            'unassigned_closeout_total' => $summary['unassigned_closeout_total'],
            'months' => $summary['months'],
        ];
    }

    /** @return array<string,mixed> */
    private function requireFund(int $userId, string $fundPublicId): array
    {
        $fund = $this->repository->findFundByPublicId($userId, $fundPublicId);
        if ($fund === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Fund not found');
        }

        return $fund;
    }

    /** @return array<string,mixed> */
    private function requireEntry(int $userId, int $fundDbId, string $entryPublicId): array
    {
        $entry = $this->repository->findEntryByPublicId($userId, $fundDbId, $entryPublicId);
        if ($entry === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Fund entry not found');
        }

        return $entry;
    }

    /** @param array<string,mixed> $entry */
    private function assertEntryEditable(array $entry): void
    {
        if (!in_array((string) $entry['source_type'], self::EDITABLE_SOURCE_TYPES, true)) {
            throw new HttpException(409, 'CONFLICT', 'This fund entry must be changed through its source workflow');
        }
        if ($entry['voided_at'] !== null || $entry['deleted_at'] !== null) {
            throw new HttpException(409, 'CONFLICT', 'This fund entry is no longer active');
        }
    }

    /** @param array<string,mixed> $entry */
    private function entryResponse(array $entry, string $fundPublicId): array
    {
        return [
            'id' => (string) $entry['fund_entry_id'],
            'fund_id' => $fundPublicId,
            'entry_date' => (string) $entry['entry_date'],
            'entry_type' => (string) $entry['entry_type'],
            'direction' => (string) $entry['direction'],
            'amount' => $this->fmt((string) $entry['amount']),
            'source_type' => (string) $entry['source_type'],
            'source_month' => $entry['source_closeout_id'] === null ? null : substr((string) $entry['entry_date'], 0, 7),
            'source_transaction_id' => $entry['source_transaction_id'] === null ? null : (string) $entry['source_transaction_id'],
            'source_closeout_id' => $entry['closeout_public_id'] === null ? null : (string) $entry['closeout_public_id'],
            'note' => $entry['note'] === null ? null : (string) $entry['note'],
            'created_at' => $this->isoDateTime((string) $entry['created_at']),
            'updated_at' => $this->isoDateTime((string) $entry['updated_at']),
        ];
    }

    private function validatedStatusFilter(mixed $value): string
    {
        $status = trim((string) ($value ?? 'active'));
        if (!in_array($status, ['active', 'archived', 'all'], true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'status', 'message' => 'must be active, archived, or all'],
            ]);
        }

        return $status;
    }

    private function validatedFundType(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::ALLOWED_FUND_TYPES, true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'fund_type', 'message' => 'must be one of goal,emergency,buffer,debt,investment,other'],
            ]);
        }

        return $value;
    }

    private function validatedEntryType(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::ALLOWED_ENTRY_TYPES, true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'entry_type', 'message' => 'must be one of contribution,withdrawal,adjustment,starting_balance'],
            ]);
        }

        return $value;
    }

    private function validateTargetMonthRequiresGoal(?string $goalAmount, ?string $targetMonthStart): void
    {
        if ($goalAmount === null && $targetMonthStart !== null) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'target_month', 'message' => 'requires goal_amount'],
            ]);
        }
    }

    private function validatedSourceType(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::ALLOWED_SOURCE_TYPES, true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'source_type', 'message' => 'must be one of manual,transaction,month_closeout,starting_balance,correction'],
            ]);
        }

        return $value;
    }

    private function validatedDirection(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['in', 'out'], true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'direction', 'message' => 'must be in or out'],
            ]);
        }

        return $value;
    }

    private function validatedName(mixed $value): string
    {
        $name = trim((string) $value);
        if ($name === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'name', 'message' => 'is required'],
            ]);
        }
        if (mb_strlen($name) > 120) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'name', 'message' => 'must be at most 120 characters'],
            ]);
        }

        return $name;
    }

    private function validatedShortString(mixed $value, string $field, int $maxLength): string
    {
        $string = trim((string) $value);
        if ($string === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'is required'],
            ]);
        }
        if (mb_strlen($string) > $maxLength) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be at most ' . $maxLength . ' characters'],
            ]);
        }

        return $string;
    }

    private function validatedMoney(mixed $value, string $field): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a decimal amount'],
            ]);
        }
        $string = trim((string) $value);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $string)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a decimal amount with at most 2 decimal places'],
            ]);
        }
        if ((float) $string <= 0.0) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be greater than 0.00'],
            ]);
        }

        return $this->fmt($string);
    }

    private function nullableMoney(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->validatedMoney($value, $field);
    }

    private function nullableNonNegativeMoney(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a decimal amount'],
            ]);
        }
        $string = trim((string) $value);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $string)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a decimal amount with at most 2 decimal places'],
            ]);
        }

        return $this->fmt($string);
    }

    private function validatedDate(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be YYYY-MM-DD'],
            ]);
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$dt || $dt->format('Y-m-d') !== $value) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a valid date'],
            ]);
        }

        return $value;
    }

    private function optionalDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->validatedDate($value, $field);
    }

    private function nullableMonthStart(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}$/', $value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be YYYY-MM'],
            ]);
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m', $value, new \DateTimeZone('UTC'));
        if (!$dt || $dt->format('Y-m') !== $value) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a valid month'],
            ]);
        }

        return $value . '-01';
    }

    private function nullableString(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a string or null'],
            ]);
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function validatedSortOrder(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
            ['field' => 'sort_order', 'message' => 'must be a non-negative integer'],
        ]);
    }

    private function validatedBoolean(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false'], true)) {
                return false;
            }
        }

        throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
            ['field' => $field, 'message' => 'must be a boolean'],
        ]);
    }

    private function optionalEnum(mixed $value, array $allowed, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'contains an unsupported value'],
            ]);
        }

        return $value;
    }

    private function validatedNumericId(mixed $value, string $field): int
    {
        if (!is_string($value) && !is_int($value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a numeric id'],
            ]);
        }
        $string = trim((string) $value);
        if ($string === '' || !ctype_digit($string)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a numeric id'],
            ]);
        }

        return (int) $string;
    }

    private function validatedExistingNumericId(mixed $value, string $field, string $table, int $userId): int
    {
        $id = $this->validatedNumericId($value, $field);
        $stmt = $this->pdo->prepare(
            "SELECT id
             FROM {$table}
             WHERE id = :id
               AND user_id = :user_id
               AND is_active = 1
               AND deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([
            ':id' => $id,
            ':user_id' => $userId,
        ]);
        if (!$stmt->fetch()) {
            throw new HttpException(404, 'NOT_FOUND', ucfirst(rtrim($table, 's')) . ' not found');
        }

        return $id;
    }

    private function fmt(string $decimal): string
    {
        return number_format((float) $decimal, 2, '.', '');
    }

    private function isoDateTime(string $value): string
    {
        $dt = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private function nowUtc(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
