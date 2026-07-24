<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use PDO;
use PDOException;

final class TaxonomyController
{
    private const ALLOWED_ICON_KEYS = [
        'home',
        'shopping_cart',
        'car',
        'plane',
        'receipt',
        'coffee',
        'smartphone',
        'credit_card',
        'piggy_bank',
        'trending_up',
        'briefcase',
        'heart',
        'dumbbell',
        'book_open',
        'film',
        'gamepad',
        'gift',
        'shield',
        'lightbulb',
        'wrench',
        'wallet',
        'tag',
    ];
    private const ALLOWED_CONTEXT_ICON_KEYS = [
        'map_pinned',
        'plane',
        'calendar_days',
        'party_popper',
        'gift',
        'heart',
        'luggage',
        'home',
        'car',
        'building',
        'landmark',
        'mountain',
        'beach',
        'globe',
        'route',
        'briefcase',
        'users',
        'star',
        'flag',
        'ticket',
        'bookmark',
        'tag',
        'box',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthService $auth
    ) {
    }

    public function listTags(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        return Response::json(['items' => $this->listByTable('tags', $ctx->userId())]);
    }

    public function tagQuickPicks(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $limit = $this->clampedQuickPickLimit($request->query['limit'] ?? null);

        $historyStmt = $this->pdo->prepare(
            'SELECT
               tg.id,
               tg.name,
               tg.icon_key,
               COUNT(t.id) AS usage_count,
               MAX(t.transaction_date) AS last_used_at
             FROM transactions t
             JOIN tags tg ON tg.id = t.tag_id
               AND tg.user_id = t.user_id
               AND tg.is_active = 1
               AND tg.deleted_at IS NULL
             WHERE t.user_id = :user_id
               AND t.deleted_at IS NULL
             GROUP BY tg.id, tg.name, tg.icon_key'
        );
        $historyStmt->execute([':user_id' => $ctx->userId()]);

        return Response::json([
            'items' => $this->buildTagQuickPicks($historyStmt->fetchAll(), $this->listByTable('tags', $ctx->userId()), $limit),
        ]);
    }

    public function createTag(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        return Response::json($this->createInTable('tags', $ctx->userId(), $request), 201);
    }

    /** @param array{tag_id:string} $params */
    public function updateTag(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $id = $this->parseEntityId($params['tag_id'] ?? '', 'tag_id');

        return Response::json($this->updateInTable('tags', $ctx->userId(), $id, $request));
    }

    /** @param array{tag_id:string} $params */
    public function deleteTag(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $id = $this->parseEntityId($params['tag_id'] ?? '', 'tag_id');

        $this->softDeleteInTable('tags', $ctx->userId(), $id);
        return Response::noContent();
    }

    public function listCards(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        return Response::json(['items' => $this->listCardsByUser($ctx->userId())]);
    }

    public function createCard(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        return Response::json($this->createInTable('cards', $ctx->userId(), $request), 201);
    }

    /** @param array{card_id:string} $params */
    public function updateCard(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $id = $this->parseEntityId($params['card_id'] ?? '', 'card_id');

        return Response::json($this->updateCardForUser($ctx->userId(), $id, $request));
    }

    /** @param array{card_id:string} $params */
    public function deleteCard(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $id = $this->parseEntityId($params['card_id'] ?? '', 'card_id');

        $this->softDeleteCard($ctx->userId(), $id);
        return Response::noContent();
    }

    public function listContexts(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        return Response::json(['items' => $this->listByTable('contexts', $ctx->userId())]);
    }

    public function createContext(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        return Response::json($this->createInTable('contexts', $ctx->userId(), $request), 201);
    }

    /** @param array{context_id:string} $params */
    public function updateContext(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $id = $this->parseEntityId($params['context_id'] ?? '', 'context_id');
        return Response::json($this->updateInTable('contexts', $ctx->userId(), $id, $request));
    }

    /** @param array{context_id:string} $params */
    public function deleteContext(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $id = $this->parseEntityId($params['context_id'] ?? '', 'context_id');
        $this->softDeleteInTable('contexts', $ctx->userId(), $id);
        return Response::noContent();
    }

    /** @return array<int,array<string,mixed>> */
    private function listByTable(string $table, int $userId): array
    {
        $selectCols = $this->tableSupportsIcons($table) ? 'id, name, icon_key' : 'id, name';
        $stmt = $this->pdo->prepare(
            "SELECT {$selectCols} FROM {$table} WHERE user_id = :user_id AND is_active = 1 AND deleted_at IS NULL ORDER BY name ASC"
        );
        $stmt->execute([':user_id' => $userId]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $item = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
            ];
            if ($this->tableSupportsIcons($table)) {
                $item['icon_key'] = $row['icon_key'] === null ? null : (string) $row['icon_key'];
            }
            $items[] = $item;
        }

        return $items;
    }

    /** @return list<array{id:string,name:string,is_favorite:bool}> */
    private function listCardsByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, is_favorite
             FROM cards
             WHERE user_id = :user_id AND is_active = 1 AND deleted_at IS NULL
             ORDER BY is_favorite DESC, LOWER(name) ASC, id ASC'
        );
        $stmt->execute([':user_id' => $userId]);

        return array_map(fn(array $row): array => $this->cardResponseFromRow($row), $stmt->fetchAll());
    }

    private function clampedQuickPickLimit(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 5;
        }

        if (!ctype_digit((string) $value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'limit', 'message' => 'must be an integer'],
            ]);
        }

        return min(max((int) $value, 1), 10);
    }

    /**
     * @param list<array<string,mixed>> $historyRows
     * @param list<array<string,mixed>> $fallbackRows
     * @return list<array<string,mixed>>
     */
    private function buildTagQuickPicks(array $historyRows, array $fallbackRows, int $limit): array
    {
        usort($historyRows, static function (array $a, array $b): int {
            $countCompare = (int) ($b['usage_count'] ?? 0) <=> (int) ($a['usage_count'] ?? 0);
            if ($countCompare !== 0) {
                return $countCompare;
            }

            $dateCompare = strcmp((string) ($b['last_used_at'] ?? ''), (string) ($a['last_used_at'] ?? ''));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        $items = [];
        $seen = [];

        foreach ($historyRows as $row) {
            if (count($items) >= $limit) {
                break;
            }

            $id = (string) ($row['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }

            $items[] = $this->tagItemFromRow($row);
            $seen[$id] = true;
        }

        foreach ($fallbackRows as $row) {
            if (count($items) >= $limit) {
                break;
            }

            $id = (string) ($row['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }

            $items[] = $this->tagItemFromRow($row);
            $seen[$id] = true;
        }

        return $items;
    }

    /** @param array<string,mixed> $row */
    private function tagItemFromRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'icon_key' => $row['icon_key'] === null ? null : (string) $row['icon_key'],
        ];
    }

    /** @return array<string,mixed> */
    private function createInTable(string $table, int $userId, Request $request): array
    {
        $name = $this->validatedName($request);
        $supportsIcons = $this->tableSupportsIcons($table);
        $iconKey = $supportsIcons
            ? $this->validatedIconKey($request, $table === 'contexts' ? self::ALLOWED_CONTEXT_ICON_KEYS : self::ALLOWED_ICON_KEYS)
            : null;
        $iconFromPayload = $supportsIcons && array_key_exists('icon_key', $request->json());

        $existingSelect = match ($table) {
            'tags', 'contexts' => "SELECT id, is_active, deleted_at, icon_key FROM {$table} WHERE user_id = :user_id AND name = :name LIMIT 1",
            'cards' => "SELECT id, is_active, deleted_at, is_favorite FROM {$table} WHERE user_id = :user_id AND name = :name LIMIT 1",
            default => "SELECT id, is_active, deleted_at FROM {$table} WHERE user_id = :user_id AND name = :name LIMIT 1",
        };
        $existingStmt = $this->pdo->prepare($existingSelect);
        $existingStmt->execute([
            ':user_id' => $userId,
            ':name' => $name,
        ]);
        $existing = $existingStmt->fetch();

        if ($existing) {
            if ((int) $existing['is_active'] === 1 && $existing['deleted_at'] === null) {
                throw new HttpException(409, 'CONFLICT', ucfirst(rtrim($table, 's')) . ' already exists');
            }

            $reactivateSql = $supportsIcons
                ? "UPDATE {$table} SET is_active = 1, deleted_at = NULL, icon_key = :icon_key, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id"
                : "UPDATE {$table} SET is_active = 1, deleted_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id";
            $reactivate = $this->pdo->prepare($reactivateSql);
            $reactivateParams = [
                ':id' => $existing['id'],
                ':user_id' => $userId,
            ];
            if ($supportsIcons) {
                $reactivateParams[':icon_key'] = $iconFromPayload ? $iconKey : ($existing['icon_key'] ?? null);
            }
            $reactivate->execute($reactivateParams);

            $response = [
                'id' => (string) $existing['id'],
                'name' => $name,
            ];
            if ($supportsIcons) {
                $response['icon_key'] = $iconFromPayload ? $iconKey : ($existing['icon_key'] === null ? null : (string) $existing['icon_key']);
            } elseif ($table === 'cards') {
                $response['is_favorite'] = ((int) ($existing['is_favorite'] ?? 0)) === 1;
            }

            return $response;
        }

        try {
            $stmt = $this->pdo->prepare(
                $supportsIcons
                    ? "INSERT INTO {$table} (user_id, name, icon_key, is_active) VALUES (:user_id, :name, :icon_key, 1)"
                    : "INSERT INTO {$table} (user_id, name, is_active) VALUES (:user_id, :name, 1)"
            );
            $insertParams = [
                ':user_id' => $userId,
                ':name' => $name,
            ];
            if ($supportsIcons) {
                $insertParams[':icon_key'] = $iconKey;
            }
            $stmt->execute($insertParams);
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? '') === '23000') {
                throw new HttpException(409, 'CONFLICT', ucfirst(rtrim($table, 's')) . ' already exists');
            }
            throw $e;
        }

        $response = [
            'id' => (string) $this->pdo->lastInsertId(),
            'name' => $name,
        ];
        if ($supportsIcons) {
            $response['icon_key'] = $iconKey;
        } elseif ($table === 'cards') {
            $response['is_favorite'] = false;
        }

        return $response;
    }

    /** @return array<string,mixed> */
    private function updateInTable(string $table, int $userId, int $id, Request $request): array
    {
        $name = $this->validatedName($request);
        $payload = $request->json();
        $supportsIcons = $this->tableSupportsIcons($table);
        $iconFromPayload = $supportsIcons && array_key_exists('icon_key', $payload);
        $iconKey = $supportsIcons && $iconFromPayload
            ? $this->validatedIconKey($request, $table === 'contexts' ? self::ALLOWED_CONTEXT_ICON_KEYS : self::ALLOWED_ICON_KEYS)
            : null;

        $exists = $this->pdo->prepare(
            $supportsIcons
                ? "SELECT id, icon_key FROM {$table} WHERE id = :id AND user_id = :user_id AND is_active = 1 AND deleted_at IS NULL LIMIT 1"
                : "SELECT id FROM {$table} WHERE id = :id AND user_id = :user_id AND is_active = 1 AND deleted_at IS NULL LIMIT 1"
        );
        $exists->execute([
            ':id' => $id,
            ':user_id' => $userId,
        ]);

        $existing = $exists->fetch();
        if (!$existing) {
            throw new HttpException(404, 'NOT_FOUND', ucfirst(rtrim($table, 's')) . ' not found');
        }

        try {
            $stmt = $this->pdo->prepare(
                $supportsIcons
                    ? "UPDATE {$table} SET name = :name, icon_key = :icon_key, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id"
                    : "UPDATE {$table} SET name = :name, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id"
            );
            $params = [
                ':name' => $name,
                ':id' => $id,
                ':user_id' => $userId,
            ];
            if ($supportsIcons) {
                $params[':icon_key'] = $iconFromPayload ? $iconKey : ($existing['icon_key'] ?? null);
            }
            $stmt->execute($params);
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? '') === '23000') {
                throw new HttpException(409, 'CONFLICT', ucfirst(rtrim($table, 's')) . ' already exists');
            }
            throw $e;
        }

        $response = [
            'id' => (string) $id,
            'name' => $name,
        ];
        if ($supportsIcons) {
            $response['icon_key'] = $iconFromPayload ? $iconKey : ($existing['icon_key'] === null ? null : (string) $existing['icon_key']);
        }

        return $response;
    }

    private function softDeleteInTable(string $table, int $userId, int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$table} SET is_active = 0, deleted_at = UTC_TIMESTAMP(), updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id AND is_active = 1 AND deleted_at IS NULL"
        );
        $stmt->execute([
            ':id' => $id,
            ':user_id' => $userId,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new HttpException(404, 'NOT_FOUND', ucfirst(rtrim($table, 's')) . ' not found');
        }
    }

    /** @return array{id:string,name:string,is_favorite:bool} */
    private function updateCardForUser(int $userId, int $id, Request $request): array
    {
        $payload = $request->json();
        $nameProvided = array_key_exists('name', $payload);
        $favoriteProvided = array_key_exists('is_favorite', $payload);

        if (!$nameProvided && !$favoriteProvided) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'request', 'message' => 'must include name or is_favorite'],
            ]);
        }

        $existing = $this->findActiveCard($userId, $id);
        $name = $nameProvided ? $this->validatedName($request) : (string) $existing['name'];
        $isFavorite = $favoriteProvided
            ? $this->validatedBoolean($payload['is_favorite'], 'is_favorite')
            : ((int) $existing['is_favorite']) === 1;

        $this->pdo->beginTransaction();
        try {
            if ($isFavorite) {
                $clearFavorite = $this->pdo->prepare(
                    'UPDATE cards
                     SET is_favorite = 0, updated_at = CURRENT_TIMESTAMP
                     WHERE user_id = :user_id
                       AND id <> :id
                       AND is_active = 1
                       AND deleted_at IS NULL
                       AND is_favorite = 1'
                );
                $clearFavorite->execute([
                    ':user_id' => $userId,
                    ':id' => $id,
                ]);
            }

            $stmt = $this->pdo->prepare(
                'UPDATE cards
                 SET name = :name,
                     is_favorite = :is_favorite,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                   AND user_id = :user_id
                   AND is_active = 1
                   AND deleted_at IS NULL'
            );
            $stmt->execute([
                ':name' => $name,
                ':is_favorite' => $isFavorite ? 1 : 0,
                ':id' => $id,
                ':user_id' => $userId,
            ]);

            if ($stmt->rowCount() === 0) {
                throw new HttpException(404, 'NOT_FOUND', 'Card not found');
            }

            $this->pdo->commit();
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if (($e->errorInfo[0] ?? '') === '23000') {
                throw new HttpException(409, 'CONFLICT', 'Card already exists');
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'id' => (string) $id,
            'name' => $name,
            'is_favorite' => $isFavorite,
        ];
    }

    private function softDeleteCard(int $userId, int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE cards
             SET is_active = 0,
                 is_favorite = 0,
                 deleted_at = UTC_TIMESTAMP(),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND user_id = :user_id
               AND is_active = 1
               AND deleted_at IS NULL'
        );
        $stmt->execute([
            ':id' => $id,
            ':user_id' => $userId,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new HttpException(404, 'NOT_FOUND', 'Card not found');
        }
    }

    private function validatedName(Request $request): string
    {
        $payload = $request->json();
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'name', 'message' => 'is required'],
            ]);
        }

        if (mb_strlen($name) > 120) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'name', 'message' => 'must be <= 120 characters'],
            ]);
        }

        return $name;
    }

    private function validatedBoolean(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a boolean'],
            ]);
        }

        return $value;
    }

    /** @param list<string> $allowedKeys */
    private function validatedIconKey(Request $request, array $allowedKeys): ?string
    {
        $payload = $request->json();

        if (!array_key_exists('icon_key', $payload) || $payload['icon_key'] === null) {
            return null;
        }

        if (!is_string($payload['icon_key'])) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'icon_key', 'message' => 'must be a string or null'],
            ]);
        }

        $iconKey = trim($payload['icon_key']);
        if ($iconKey === '') {
            return null;
        }

        if (!in_array($iconKey, $allowedKeys, true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'icon_key', 'message' => 'unsupported icon key'],
            ]);
        }

        return $iconKey;
    }

    private function tableSupportsIcons(string $table): bool
    {
        return in_array($table, ['tags', 'contexts'], true);
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

    /** @return array<string,mixed> */
    private function findActiveCard(int $userId, int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, is_favorite
             FROM cards
             WHERE id = :id
               AND user_id = :user_id
               AND is_active = 1
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([
            ':id' => $id,
            ':user_id' => $userId,
        ]);

        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException(404, 'NOT_FOUND', 'Card not found');
        }

        return $row;
    }

    /** @param array<string,mixed> $row
     *  @return array{id:string,name:string,is_favorite:bool}
     */
    private function cardResponseFromRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'is_favorite' => ((int) ($row['is_favorite'] ?? 0)) === 1,
        ];
    }
}
