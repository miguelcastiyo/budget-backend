<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Funds\FundTransactionIntegrationService;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Recurring\RecurringExpenseService;
use App\Support\TransactionNotes;
use PDO;
use PDOException;

final class TransactionController
{
    private const ALLOWED_CATEGORIES = ['needs', 'wants', 'savings'];
    private const SUGGESTION_CANDIDATE_LIMIT = 300;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthService $auth,
        private readonly RecurringExpenseService $recurring,
        private readonly FundTransactionIntegrationService $fundTransactionIntegrationService
    ) {
    }

    public function list(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $query = $request->query;

        [$dateFrom, $dateTo] = $this->resolveDateRange($query);
        $this->recurring->ensureGeneratedForDateRange($ctx->userId(), $dateFrom, $dateTo);

        $where = ['t.user_id = :user_id', 't.deleted_at IS NULL'];
        $params = [':user_id' => $ctx->userId()];

        if ($dateFrom !== null && $dateTo !== null) {
            $where[] = 't.transaction_date BETWEEN :date_from AND :date_to';
            $params[':date_from'] = $dateFrom;
            $params[':date_to'] = $dateTo;
        }

        $categories = $this->parseCategoryCsv((string) ($query['categories'] ?? ''));
        if ($categories !== []) {
            $holders = [];
            foreach ($categories as $i => $cat) {
                $key = ':cat_' . $i;
                $holders[] = $key;
                $params[$key] = $cat;
            }
            $where[] = 't.category IN (' . implode(', ', $holders) . ')';
        }

        $tagIds = $this->parseIdCsv((string) ($query['tag_ids'] ?? ''), 'tag_ids');
        if ($tagIds !== []) {
            $holders = [];
            foreach ($tagIds as $i => $id) {
                $key = ':tag_' . $i;
                $holders[] = $key;
                $params[$key] = $id;
            }
            $where[] = 't.tag_id IN (' . implode(', ', $holders) . ')';
        }

        $cardIds = $this->parseIdCsv((string) ($query['card_ids'] ?? ''), 'card_ids');
        if ($cardIds !== []) {
            $holders = [];
            foreach ($cardIds as $i => $id) {
                $key = ':card_' . $i;
                $holders[] = $key;
                $params[$key] = $id;
            }
            $where[] = 't.card_id IN (' . implode(', ', $holders) . ')';
        }

        $contextIds = $this->parseIdCsv((string) ($query['context_ids'] ?? ''), 'context_ids');
        if ($contextIds !== []) {
            $holders = [];
            foreach ($contextIds as $i => $id) {
                $key = ':context_' . $i;
                $holders[] = $key;
                $params[$key] = $id;
            }
            $where[] = 't.context_id IN (' . implode(', ', $holders) . ')';
        }

        $splitFilter = $this->parseSplitFilter($query['is_split'] ?? null, 'is_split');
        if ($splitFilter !== null) {
            $where[] = 't.is_split = :is_split';
            $params[':is_split'] = $splitFilter;
        }

        $searchQuery = $this->validatedSearchQuery($query['q'] ?? null, 'q');
        if ($searchQuery !== null) {
            $where[] = '(LOWER(t.expense) LIKE :search_expense_query OR LOWER(tg.name) LIKE :search_tag_query OR LOWER(COALESCE(cx.name, \'\')) LIKE :search_context_query OR LOWER(COALESCE(c.name, \'\')) LIKE :search_card_query)';
            $params[':search_expense_query'] = '%' . $searchQuery . '%';
            $params[':search_tag_query'] = '%' . $searchQuery . '%';
            $params[':search_context_query'] = '%' . $searchQuery . '%';
            $params[':search_card_query'] = '%' . $searchQuery . '%';
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $pageSize = (int) ($query['page_size'] ?? 50);
        if ($pageSize < 1 || $pageSize > 200) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'page_size', 'message' => 'must be between 1 and 200'],
            ]);
        }

        $sort = (string) ($query['sort'] ?? 'date_desc');
        $orderBy = match ($sort) {
            'date_desc' => 't.transaction_date DESC, t.id DESC',
            'date_asc' => 't.transaction_date ASC, t.id ASC',
            default => throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'sort', 'message' => 'must be date_desc or date_asc'],
            ]),
        };

        $whereSql = implode(' AND ', $where);

        $summaryStmt = $this->pdo->prepare(
            'SELECT
               COUNT(*) AS total,
               COALESCE(SUM(t.amount), 0) AS total_spent,
               COALESCE(AVG(t.amount), 0) AS avg_transaction,
               COALESCE(SUM(CASE WHEN t.is_split = 1 THEN 1 ELSE 0 END), 0) AS split_count
             FROM transactions t
             JOIN tags tg ON tg.id = t.tag_id AND tg.user_id = t.user_id
             LEFT JOIN cards c ON c.id = t.card_id AND c.user_id = t.user_id
             LEFT JOIN contexts cx ON cx.id = t.context_id AND cx.user_id = t.user_id
             WHERE ' . $whereSql
        );
        foreach ($params as $k => $v) {
            $summaryStmt->bindValue($k, $v);
        }
        $summaryStmt->execute();
        $summaryRow = $summaryStmt->fetch() ?: [];
        $totalItems = (int) ($summaryRow['total'] ?? 0);

        $offset = ($page - 1) * $pageSize;
        $sql = <<<'SQL'
SELECT
  t.id,
  t.transaction_date,
  t.expense,
  t.amount,
  t.category,
  t.is_split,
  t.notes,
  t.source,
  tg.id AS tag_id,
  tg.name AS tag_name,
  tg.icon_key AS tag_icon_key,
  cx.id AS context_id,
  cx.name AS context_name,
  c.id AS card_id,
  c.name AS card_name,
  c.is_favorite AS card_is_favorite,
  reo.recurring_expense_id,
  t.created_at,
  t.updated_at
FROM transactions t
JOIN tags tg ON tg.id = t.tag_id AND tg.user_id = t.user_id
LEFT JOIN cards c ON c.id = t.card_id AND c.user_id = t.user_id
LEFT JOIN contexts cx ON cx.id = t.context_id AND cx.user_id = t.user_id
LEFT JOIN (
  SELECT user_id, transaction_id, MIN(recurring_expense_id) AS recurring_expense_id
  FROM recurring_expense_occurrences
  WHERE transaction_id IS NOT NULL
  GROUP BY user_id, transaction_id
) reo ON reo.transaction_id = t.id AND reo.user_id = t.user_id
WHERE %s
ORDER BY %s
LIMIT :limit OFFSET :offset
SQL;

        $stmt = $this->pdo->prepare(sprintf($sql, $whereSql, $orderBy));
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->mapTransactionRow($row);
        }

        return Response::json([
            'items' => $items,
            'page' => $page,
            'page_size' => $pageSize,
            'total_items' => $totalItems,
            'summary' => [
                'total_spent' => $this->fmt((string) ($summaryRow['total_spent'] ?? '0')),
                'count' => $totalItems,
                'avg_transaction' => $this->fmt((string) ($summaryRow['avg_transaction'] ?? '0')),
                'split_count' => (int) ($summaryRow['split_count'] ?? 0),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $payload = $request->json();

        $date = $this->validatedDate($payload['date'] ?? null, 'date');
        $expense = $this->validatedExpense($payload['expense'] ?? null);
        $amount = $this->validatedMoney($payload['amount'] ?? null, 'amount');
        $category = $this->validatedCategory($payload['category'] ?? null);
        $tagId = $this->resolveTagIdFromPayload($payload, $ctx->userId(), required: true);
        $this->pdo->beginTransaction();
        try {
            $contextId = $this->resolveContextIdFromPayload($payload, $ctx->userId(), allowClear: false);
            $cardId = $this->resolveCardIdFromPayload($payload, $ctx->userId(), required: false, allowClear: false);
        $isSplit = array_key_exists('is_split', $payload)
            ? $this->validatedBoolean($payload['is_split'], 'is_split')
            : false;
        $notes = array_key_exists('notes', $payload)
            ? TransactionNotes::normalize($payload['notes'])
            : null;

            $stmt = $this->pdo->prepare(
                "INSERT INTO transactions (user_id, transaction_date, expense, amount, category, tag_id, context_id, card_id, is_split, notes, source) VALUES (:user_id, :transaction_date, :expense, :amount, :category, :tag_id, :context_id, :card_id, :is_split, :notes, 'manual')"
            );
            $stmt->execute([
            ':user_id' => $ctx->userId(),
            ':transaction_date' => $date,
            ':expense' => $expense,
            ':amount' => $amount,
            ':category' => $category,
            ':tag_id' => $tagId,
            ':context_id' => $contextId,
                ':card_id' => $cardId,
            ':is_split' => $isSplit ? 1 : 0,
                ':notes' => $notes,
            ]);
            $transactionId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return Response::json($this->fetchTransaction($ctx->userId(), $transactionId), 201);
    }

    public function suggestions(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $query = $this->validatedSuggestionQuery($request->query['q'] ?? null);
        $limit = $this->validatedSuggestionLimit($request->query['limit'] ?? null);
        $lowerQuery = mb_strtolower($query);

        $sql = <<<'SQL'
SELECT
  t.id,
  t.expense,
  t.category,
  t.is_split,
  t.transaction_date,
  tg.id AS tag_id,
  tg.name AS tag_name,
  tg.icon_key AS tag_icon_key,
  c.id AS card_id,
  c.name AS card_name,
  c.is_favorite AS card_is_favorite
FROM transactions t
JOIN tags tg ON tg.id = t.tag_id
  AND tg.user_id = t.user_id
  AND tg.is_active = 1
  AND tg.deleted_at IS NULL
LEFT JOIN cards c ON c.id = t.card_id
  AND c.user_id = t.user_id
  AND c.is_active = 1
  AND c.deleted_at IS NULL
WHERE t.user_id = :user_id
  AND t.deleted_at IS NULL
  AND LOWER(t.expense) LIKE :contains_query
  AND (t.card_id IS NULL OR c.id IS NOT NULL)
ORDER BY
  CASE
    WHEN LOWER(TRIM(t.expense)) = :exact_query THEN 0
    WHEN LOWER(TRIM(t.expense)) LIKE :prefix_query THEN 1
    ELSE 2
  END,
  t.transaction_date DESC,
  t.id DESC
LIMIT :candidate_limit
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':user_id', $ctx->userId(), PDO::PARAM_INT);
        $stmt->bindValue(':contains_query', '%' . $lowerQuery . '%');
        $stmt->bindValue(':exact_query', $lowerQuery);
        $stmt->bindValue(':prefix_query', $lowerQuery . '%');
        $stmt->bindValue(':candidate_limit', self::SUGGESTION_CANDIDATE_LIMIT, PDO::PARAM_INT);
        $stmt->execute();

        return Response::json([
            'items' => array_slice($this->buildTransactionSuggestions($stmt->fetchAll(), $query), 0, $limit),
        ]);
    }

    /** @param array{transaction_id:string} $params */
    public function update(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $transactionId = $this->parseEntityId((string) ($params['transaction_id'] ?? ''), 'transaction_id');

        $existing = $this->fetchRawTransaction($ctx->userId(), $transactionId);
        $linkedFundEntry = $this->fundTransactionIntegrationService->findActiveEntryByTransactionId($ctx->userId(), $transactionId);
        $payload = $request->json();

        $date = array_key_exists('date', $payload)
            ? $this->validatedDate($payload['date'], 'date')
            : (string) $existing['transaction_date'];

        $expense = array_key_exists('expense', $payload)
            ? $this->validatedExpense($payload['expense'])
            : (string) $existing['expense'];

        $amount = array_key_exists('amount', $payload)
            ? $this->validatedMoney($payload['amount'], 'amount')
            : $this->fmt((string) $existing['amount']);

        $category = array_key_exists('category', $payload)
            ? $this->validatedCategory($payload['category'])
            : (string) $existing['category'];

        $tagId = (array_key_exists('tag_id', $payload) || array_key_exists('tag', $payload))
            ? $this->resolveTagIdFromPayload($payload, $ctx->userId(), required: true)
            : (int) $existing['tag_id'];

        $cardId = (array_key_exists('card_id', $payload) || array_key_exists('card', $payload))
            ? $this->resolveCardIdFromPayload($payload, $ctx->userId(), required: false, allowClear: true)
            : ($existing['card_id'] === null ? null : (int) $existing['card_id']);
        $contextId = (array_key_exists('context_id', $payload) || array_key_exists('context', $payload))
            ? $this->resolveContextIdFromPayload($payload, $ctx->userId(), allowClear: true)
            : ($existing['context_id'] === null ? null : (int) $existing['context_id']);
        $isSplit = array_key_exists('is_split', $payload)
            ? $this->validatedBoolean($payload['is_split'], 'is_split')
            : ((int) $existing['is_split'] === 1);
        $notes = array_key_exists('notes', $payload)
            ? TransactionNotes::normalize($payload['notes'])
            : ($existing['notes'] === null ? null : (string) $existing['notes']);

        $stmt = $this->pdo->prepare(
            'UPDATE transactions SET transaction_date = :transaction_date, expense = :expense, amount = :amount, category = :category, tag_id = :tag_id, context_id = :context_id, card_id = :card_id, is_split = :is_split, notes = :notes, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL'
        );
        $stmt->execute([
            ':transaction_date' => $date,
            ':expense' => $expense,
            ':amount' => $amount,
            ':category' => $category,
            ':tag_id' => $tagId,
            ':context_id' => $contextId,
            ':card_id' => $cardId,
            ':is_split' => $isSplit ? 1 : 0,
            ':notes' => $notes,
            ':id' => $transactionId,
            ':user_id' => $ctx->userId(),
        ]);
        if ($linkedFundEntry !== null) {
            $this->fundTransactionIntegrationService->syncLinkedTransactionUpdate(
                $ctx->userId(),
                $existing,
                $linkedFundEntry,
                $date,
                $amount,
                $category,
                $notes
            );
        }

        return Response::json($this->fetchTransaction($ctx->userId(), $transactionId));
    }

    /** @param array{transaction_id:string} $params */
    public function delete(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $transactionId = $this->parseEntityId((string) ($params['transaction_id'] ?? ''), 'transaction_id');

        $stmt = $this->pdo->prepare(
            'UPDATE transactions SET deleted_at = UTC_TIMESTAMP(), updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL'
        );
        $stmt->execute([
            ':id' => $transactionId,
            ':user_id' => $ctx->userId(),
        ]);

        if ($stmt->rowCount() === 0) {
            throw new HttpException(404, 'NOT_FOUND', 'Transaction not found');
        }

        $this->fundTransactionIntegrationService->voidLinkedTransactionDelete(
            $ctx->userId(),
            $transactionId,
            gmdate('Y-m-d H:i:s')
        );

        return Response::noContent();
    }

    /** @return array<string,mixed> */
    private function fetchRawTransaction(int $userId, int $transactionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, transaction_date, expense, amount, category, tag_id, context_id, card_id, is_split, notes FROM transactions WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([
            ':id' => $transactionId,
            ':user_id' => $userId,
        ]);

        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException(404, 'NOT_FOUND', 'Transaction not found');
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function fetchTransaction(int $userId, int $transactionId): array
    {
        $sql = <<<'SQL'
SELECT
  t.id,
  t.transaction_date,
  t.expense,
  t.amount,
  t.category,
  t.is_split,
  t.notes,
  t.source,
  tg.id AS tag_id,
  tg.name AS tag_name,
  tg.icon_key AS tag_icon_key,
  cx.id AS context_id,
  cx.name AS context_name,
  c.id AS card_id,
  c.name AS card_name,
  c.is_favorite AS card_is_favorite,
  reo.recurring_expense_id,
  t.created_at,
  t.updated_at
FROM transactions t
JOIN tags tg ON tg.id = t.tag_id AND tg.user_id = t.user_id
LEFT JOIN cards c ON c.id = t.card_id AND c.user_id = t.user_id
LEFT JOIN contexts cx ON cx.id = t.context_id AND cx.user_id = t.user_id
LEFT JOIN (
  SELECT user_id, transaction_id, MIN(recurring_expense_id) AS recurring_expense_id
  FROM recurring_expense_occurrences
  WHERE transaction_id IS NOT NULL
  GROUP BY user_id, transaction_id
) reo ON reo.transaction_id = t.id AND reo.user_id = t.user_id
WHERE t.id = :id AND t.user_id = :user_id AND t.deleted_at IS NULL
LIMIT 1
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $transactionId,
            ':user_id' => $userId,
        ]);

        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException(404, 'NOT_FOUND', 'Transaction not found');
        }

        return $this->mapTransactionRow($row);
    }

    /** @param array<string,mixed> $row */
    private function mapTransactionRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'date' => (string) $row['transaction_date'],
            'expense' => (string) $row['expense'],
            'amount' => $this->fmt((string) $row['amount']),
            'category' => (string) $row['category'],
            'is_split' => ((int) $row['is_split']) === 1,
            'notes' => $row['notes'] === null ? null : (string) $row['notes'],
            'source' => (string) $row['source'],
            'recurring_expense_id' => $row['recurring_expense_id'] === null ? null : (string) $row['recurring_expense_id'],
            'tag' => [
                'id' => (string) $row['tag_id'],
                'name' => (string) $row['tag_name'],
                'icon_key' => $row['tag_icon_key'] === null ? null : (string) $row['tag_icon_key'],
            ],
            'context' => $row['context_id'] === null
                ? null
                : [
                    'id' => (string) $row['context_id'],
                    'name' => (string) $row['context_name'],
                ],
            'card' => $row['card_id'] === null
                ? null
                : [
                    'id' => (string) $row['card_id'],
                    'name' => (string) $row['card_name'],
                    'is_favorite' => ((int) ($row['card_is_favorite'] ?? 0)) === 1,
                ],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private function validatedSuggestionQuery(mixed $value): string
    {
        $query = trim((string) $value);
        $query = preg_replace('/\s+/', ' ', $query) ?? $query;
        $length = mb_strlen($query);

        if ($length < 2 || $length > 80) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'q', 'message' => 'must be between 2 and 80 characters'],
            ]);
        }

        return $query;
    }

    private function validatedSuggestionLimit(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 5;
        }

        if (!ctype_digit((string) $value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'limit', 'message' => 'must be between 1 and 10'],
            ]);
        }

        $limit = (int) $value;
        if ($limit < 1 || $limit > 10) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'limit', 'message' => 'must be between 1 and 10'],
            ]);
        }

        return $limit;
    }

    /** @param list<array<string,mixed>> $rows */
    private function buildTransactionSuggestions(array $rows, string $query): array
    {
        $normalizedQuery = $this->normalizeSuggestionText($query);
        $groups = [];

        foreach ($rows as $row) {
            $normalizedExpense = $this->normalizeSuggestionText((string) ($row['expense'] ?? ''));
            if ($normalizedExpense === '' || !str_contains($normalizedExpense, $normalizedQuery)) {
                continue;
            }

            if (!isset($groups[$normalizedExpense])) {
                $groups[$normalizedExpense] = [
                    'expense' => (string) $row['expense'],
                    'usage_count' => 0,
                    'last_used_at' => (string) $row['transaction_date'],
                    'last_id' => (int) $row['id'],
                    'match_rank' => $this->suggestionMatchRank($normalizedExpense, $normalizedQuery),
                    'categories' => [],
                    'tags' => [],
                    'cards' => [],
                    'splits' => [],
                ];
            }

            $group = &$groups[$normalizedExpense];
            $usedAt = (string) $row['transaction_date'];
            $rowId = (int) $row['id'];
            if ($usedAt > $group['last_used_at'] || ($usedAt === $group['last_used_at'] && $rowId > $group['last_id'])) {
                $group['expense'] = (string) $row['expense'];
                $group['last_used_at'] = $usedAt;
                $group['last_id'] = $rowId;
            }

            $group['usage_count']++;
            $this->countSuggestionValue($group['categories'], (string) $row['category'], $usedAt, $rowId);
            $this->countSuggestionValue($group['tags'], (string) $row['tag_id'], $usedAt, $rowId, [
                'id' => (string) $row['tag_id'],
                'name' => (string) $row['tag_name'],
                'icon_key' => $row['tag_icon_key'] === null ? null : (string) $row['tag_icon_key'],
            ]);
            $this->countSuggestionValue($group['cards'], $row['card_id'] === null ? '' : (string) $row['card_id'], $usedAt, $rowId, $row['card_id'] === null ? null : [
                'id' => (string) $row['card_id'],
                'name' => (string) $row['card_name'],
                'is_favorite' => ((int) ($row['card_is_favorite'] ?? 0)) === 1,
            ]);
            $this->countSuggestionValue($group['splits'], ((int) $row['is_split']) === 1 ? '1' : '0', $usedAt, $rowId);
            unset($group);
        }

        $suggestions = [];
        foreach ($groups as $group) {
            $category = $this->bestSuggestionValue($group['categories']);
            $tag = $this->bestSuggestionValue($group['tags']);
            $card = $this->bestSuggestionValue($group['cards']);
            $split = $this->bestSuggestionValue($group['splits']);

            if ($category === null || $tag === null || $split === null) {
                continue;
            }

            $suggestions[] = [
                'expense' => $group['expense'],
                'category' => $category['value'],
                'tag' => $tag['payload'],
                'card' => $card['payload'],
                'is_split' => $split['value'] === '1',
                'confidence' => $this->suggestionConfidence((int) $group['match_rank'], (int) $group['usage_count'], max((int) $category['count'], (int) $tag['count'])),
                'last_used_at' => $group['last_used_at'],
                'usage_count' => (int) $group['usage_count'],
                '_rank' => [
                    'match' => (int) $group['match_rank'],
                    'usage' => (int) $group['usage_count'],
                    'last_used_at' => $group['last_used_at'],
                    'last_id' => (int) $group['last_id'],
                ],
            ];
        }

        usort($suggestions, static function (array $a, array $b): int {
            $rankA = $a['_rank'];
            $rankB = $b['_rank'];

            return [$rankA['match'], -$rankA['usage'], $rankB['last_used_at'], -$rankA['last_id']]
                <=> [$rankB['match'], -$rankB['usage'], $rankA['last_used_at'], -$rankB['last_id']];
        });

        return array_map(static function (array $suggestion): array {
            unset($suggestion['_rank']);
            return $suggestion;
        }, $suggestions);
    }

    private function normalizeSuggestionText(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }

    private function suggestionMatchRank(string $normalizedExpense, string $normalizedQuery): int
    {
        if ($normalizedExpense === $normalizedQuery) {
            return 0;
        }

        if (str_starts_with($normalizedExpense, $normalizedQuery)) {
            return 1;
        }

        return 2;
    }

    /** @param array<string,array{value:string,count:int,last_used_at:string,last_id:int,payload:mixed}> $bucket */
    private function countSuggestionValue(array &$bucket, string $value, string $usedAt, int $rowId, mixed $payload = null): void
    {
        if (!isset($bucket[$value])) {
            $bucket[$value] = [
                'value' => $value,
                'count' => 0,
                'last_used_at' => $usedAt,
                'last_id' => $rowId,
                'payload' => $payload ?? $value,
            ];
        }

        $bucket[$value]['count']++;
        if ($usedAt > $bucket[$value]['last_used_at'] || ($usedAt === $bucket[$value]['last_used_at'] && $rowId > $bucket[$value]['last_id'])) {
            $bucket[$value]['last_used_at'] = $usedAt;
            $bucket[$value]['last_id'] = $rowId;
            $bucket[$value]['payload'] = $payload ?? $value;
        }
    }

    /** @param array<string,array{value:string,count:int,last_used_at:string,last_id:int,payload:mixed}> $bucket */
    private function bestSuggestionValue(array $bucket): ?array
    {
        if ($bucket === []) {
            return null;
        }

        usort($bucket, static fn(array $a, array $b): int => [
            -$a['count'],
            $b['last_used_at'],
            -$a['last_id'],
        ] <=> [
            -$b['count'],
            $a['last_used_at'],
            -$b['last_id'],
        ]);

        return $bucket[0];
    }

    private function suggestionConfidence(int $matchRank, int $usageCount, int $strongestSetupCount): string
    {
        if ($matchRank === 0 && ($usageCount >= 2 || $strongestSetupCount >= 2)) {
            return 'high';
        }

        if ($matchRank <= 1 || ($matchRank === 0 && $usageCount === 1)) {
            return 'medium';
        }

        return 'low';
    }

    /** @param array<string,mixed> $payload */
    private function resolveTagIdFromPayload(array $payload, int $userId, bool $required): int
    {
        if (array_key_exists('tag_id', $payload) && $payload['tag_id'] !== null) {
            $tagId = $this->parseEntityId((string) $payload['tag_id'], 'tag_id');
            $this->assertTagExists($userId, $tagId);
            return $tagId;
        }

        if (array_key_exists('tag', $payload) && is_array($payload['tag'])) {
            $name = trim((string) (($payload['tag']['name'] ?? '') ?: ''));
            if ($name === '') {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'tag.name', 'message' => 'is required'],
                ]);
            }

            return $this->findOrCreateTag($userId, $name);
        }

        if ($required) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'tag_id', 'message' => 'tag_id or tag.name is required'],
            ]);
        }

        return 0;
    }

    /** @param array<string,mixed> $payload */
    private function resolveCardIdFromPayload(array $payload, int $userId, bool $required, bool $allowClear): ?int
    {
        if (array_key_exists('card_id', $payload)) {
            if ($payload['card_id'] === null) {
                if ($allowClear) {
                    return null;
                }
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'card_id', 'message' => 'cannot be null here'],
                ]);
            }

            $cardId = $this->parseEntityId((string) $payload['card_id'], 'card_id');
            $this->assertCardExists($userId, $cardId);
            return $cardId;
        }

        if (array_key_exists('card', $payload)) {
            if ($payload['card'] === null && $allowClear) {
                return null;
            }

            if (is_array($payload['card'])) {
                $name = trim((string) (($payload['card']['name'] ?? '') ?: ''));
                if ($name === '') {
                    throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                        ['field' => 'card.name', 'message' => 'is required'],
                    ]);
                }

                return $this->findOrCreateCard($userId, $name);
            }

            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'card', 'message' => 'must be an object with name or null'],
            ]);
        }

        if ($required) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'card_id', 'message' => 'is required'],
            ]);
        }

        return null;
    }

    /** @param array<string,mixed> $payload */
    private function resolveContextIdFromPayload(array $payload, int $userId, bool $allowClear): ?int
    {
        if (array_key_exists('context_id', $payload) && array_key_exists('context', $payload)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'context', 'message' => 'context_id and context are mutually exclusive'],
            ]);
        }

        if (array_key_exists('context_id', $payload)) {
            if ($payload['context_id'] === null) {
                if ($allowClear) {
                    return null;
                }
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'context_id', 'message' => 'cannot be null here'],
                ]);
            }
            $id = $this->parseEntityId((string) $payload['context_id'], 'context_id');
            $this->assertContextExists($userId, $id);
            return $id;
        }

        if (array_key_exists('context', $payload)) {
            if ($payload['context'] === null && $allowClear) {
                return null;
            }
            if (!is_array($payload['context'])) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'context', 'message' => 'must be an object with name or null'],
                ]);
            }
            $name = trim((string) (($payload['context']['name'] ?? '') ?: ''));
            if ($name === '') {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'context.name', 'message' => 'is required'],
                ]);
            }
            if (mb_strlen($name) > 120) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'context.name', 'message' => 'must be <= 120 characters'],
                ]);
            }
            return $this->findOrCreateContext($userId, $name);
        }

        return null;
    }

    private function assertTagExists(int $userId, int $tagId): void
    {
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
    }

    private function assertCardExists(int $userId, int $cardId): void
    {
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
    }

    private function assertContextExists(int $userId, int $contextId): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM contexts WHERE id = :id AND user_id = :user_id AND is_active = 1 AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([':id' => $contextId, ':user_id' => $userId]);
        if (!$stmt->fetch()) {
            throw new HttpException(404, 'NOT_FOUND', 'Context not found');
        }
    }

    private function findOrCreateTag(int $userId, string $name): int
    {
        $select = $this->pdo->prepare(
            'SELECT id, is_active, deleted_at FROM tags WHERE user_id = :user_id AND name = :name LIMIT 1'
        );
        $select->execute([
            ':user_id' => $userId,
            ':name' => $name,
        ]);
        $row = $select->fetch();
        if ($row) {
            if ((int) $row['is_active'] === 0 || $row['deleted_at'] !== null) {
                $reactivate = $this->pdo->prepare(
                    'UPDATE tags SET is_active = 1, deleted_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id'
                );
                $reactivate->execute([
                    ':id' => $row['id'],
                    ':user_id' => $userId,
                ]);
            }
            return (int) $row['id'];
        }

        try {
            $insert = $this->pdo->prepare('INSERT INTO tags (user_id, name, is_active) VALUES (:user_id, :name, 1)');
            $insert->execute([
                ':user_id' => $userId,
                ':name' => $name,
            ]);
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? '') === '23000') {
                throw new HttpException(409, 'CONFLICT', 'Tag already exists');
            }
            throw $e;
        }
    }

    private function findOrCreateCard(int $userId, string $name): int
    {
        $select = $this->pdo->prepare(
            'SELECT id, is_active, deleted_at FROM cards WHERE user_id = :user_id AND name = :name LIMIT 1'
        );
        $select->execute([
            ':user_id' => $userId,
            ':name' => $name,
        ]);
        $row = $select->fetch();
        if ($row) {
            if ((int) $row['is_active'] === 0 || $row['deleted_at'] !== null) {
                $reactivate = $this->pdo->prepare(
                    'UPDATE cards SET is_active = 1, deleted_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id'
                );
                $reactivate->execute([
                    ':id' => $row['id'],
                    ':user_id' => $userId,
                ]);
            }
            return (int) $row['id'];
        }

        try {
            $insert = $this->pdo->prepare('INSERT INTO cards (user_id, name, is_active) VALUES (:user_id, :name, 1)');
            $insert->execute([
                ':user_id' => $userId,
                ':name' => $name,
            ]);
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? '') === '23000') {
                throw new HttpException(409, 'CONFLICT', 'Card already exists');
            }
            throw $e;
        }
    }

    private function findOrCreateContext(int $userId, string $name): int
    {
        $select = $this->pdo->prepare('SELECT id, is_active, deleted_at FROM contexts WHERE user_id = :user_id AND name = :name LIMIT 1');
        $select->execute([':user_id' => $userId, ':name' => $name]);
        $row = $select->fetch();
        if ($row) {
            if ((int) $row['is_active'] === 0 || $row['deleted_at'] !== null) {
                $reactivate = $this->pdo->prepare('UPDATE contexts SET is_active = 1, deleted_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id');
                $reactivate->execute([':id' => $row['id'], ':user_id' => $userId]);
            }
            return (int) $row['id'];
        }

        try {
            $insert = $this->pdo->prepare('INSERT INTO contexts (user_id, name, is_active) VALUES (:user_id, :name, 1)');
            $insert->execute([':user_id' => $userId, ':name' => $name]);
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? '') === '23000') {
                throw new HttpException(409, 'CONFLICT', 'Context already exists');
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $query */
    private function resolveDateRange(array $query): array
    {
        $dateFromRaw = trim((string) ($query['date_from'] ?? ''));
        $dateToRaw = trim((string) ($query['date_to'] ?? ''));
        $preset = trim((string) ($query['preset'] ?? ''));

        $hasCustom = ($dateFromRaw !== '' || $dateToRaw !== '');

        if ($hasCustom && $preset !== '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'preset', 'message' => 'cannot be combined with date_from/date_to'],
            ]);
        }

        if ($hasCustom) {
            if ($dateFromRaw === '' || $dateToRaw === '') {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'date_range', 'message' => 'date_from and date_to are both required'],
                ]);
            }

            $dateFrom = $this->validatedDate($dateFromRaw, 'date_from');
            $dateTo = $this->validatedDate($dateToRaw, 'date_to');
            if ($dateFrom > $dateTo) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'date_range', 'message' => 'date_from must be <= date_to'],
                ]);
            }

            return [$dateFrom, $dateTo];
        }

        if ($preset === '') {
            return [null, null];
        }

        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));

        return match ($preset) {
            'last_7_days' => [$today->modify('-6 days')->format('Y-m-d'), $today->format('Y-m-d')],
            'last_30_days' => [$today->modify('-29 days')->format('Y-m-d'), $today->format('Y-m-d')],
            'month_to_date' => [$today->modify('first day of this month')->format('Y-m-d'), $today->format('Y-m-d')],
            'last_month' => [
                $today->modify('first day of last month')->format('Y-m-d'),
                $today->modify('last day of last month')->format('Y-m-d'),
            ],
            'quarter_to_date' => [$this->quarterStart($today)->format('Y-m-d'), $today->format('Y-m-d')],
            default => throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'preset', 'message' => 'unsupported preset'],
            ]),
        };
    }

    private function quarterStart(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $month = (int) $date->format('n');
        $quarterStartMonth = (int) (floor(($month - 1) / 3) * 3 + 1);
        return $date->setDate((int) $date->format('Y'), $quarterStartMonth, 1);
    }

    private function validatedDate(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be YYYY-MM-DD'],
            ]);
        }

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (!$dt || $dt->format('Y-m-d') !== $value) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a valid date'],
            ]);
        }

        return $value;
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
                ['field' => 'category', 'message' => 'must be one of needs,wants,savings'],
            ]);
        }

        return $value;
    }

    private function validatedBoolean(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '1' || $normalized === 'true') {
                return true;
            }
            if ($normalized === '0' || $normalized === 'false') {
                return false;
            }
        }

        throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
            ['field' => $field, 'message' => 'must be a boolean'],
        ]);
    }

    /** @return list<string> */
    private function parseCategoryCsv(string $csv): array
    {
        $csv = trim($csv);
        if ($csv === '') {
            return [];
        }

        $items = array_values(array_filter(array_map('trim', explode(',', $csv)), static fn(string $v) => $v !== ''));
        foreach ($items as $item) {
            if (!in_array($item, self::ALLOWED_CATEGORIES, true)) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'categories', 'message' => 'contains invalid value: ' . $item],
                ]);
            }
        }

        return $items;
    }

    /** @return list<int> */
    private function parseIdCsv(string $csv, string $field): array
    {
        $csv = trim($csv);
        if ($csv === '') {
            return [];
        }

        $items = array_values(array_filter(array_map('trim', explode(',', $csv)), static fn(string $v) => $v !== ''));
        $ids = [];

        foreach ($items as $item) {
            if (!ctype_digit($item)) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => $field, 'message' => 'must contain numeric IDs'],
                ]);
            }
            $ids[] = (int) $item;
        }

        return $ids;
    }

    private function parseSplitFilter(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '' || $normalized === 'all') {
            return null;
        }

        if ($normalized === '1' || $normalized === 'true' || $normalized === 'split') {
            return 1;
        }

        if ($normalized === '0' || $normalized === 'false' || $normalized === 'not_split') {
            return 0;
        }

        throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
            ['field' => $field, 'message' => 'must be all, split, or not_split'],
        ]);
    }

    private function validatedSearchQuery(mixed $value, string $field): ?string
    {
        $normalized = strtolower(trim((string) ($value ?? '')));
        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) > 120) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be <= 120 characters'],
            ]);
        }

        return $normalized;
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

    private function fmt(string $decimal): string
    {
        return number_format((float) $decimal, 2, '.', '');
    }
}
