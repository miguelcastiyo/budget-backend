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
    private const ALLOWED_TAG_ICON_KEYS = [
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
        return Response::json(['items' => $this->listByTable('cards', $ctx->userId())]);
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

        return Response::json($this->updateInTable('cards', $ctx->userId(), $id, $request));
    }

    /** @param array{card_id:string} $params */
    public function deleteCard(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $id = $this->parseEntityId($params['card_id'] ?? '', 'card_id');

        $this->softDeleteInTable('cards', $ctx->userId(), $id);
        return Response::noContent();
    }

    /** @return array<int,array<string,mixed>> */
    private function listByTable(string $table, int $userId): array
    {
        $selectCols = $table === 'tags' ? 'id, name, icon_key' : 'id, name';
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
            if ($table === 'tags') {
                $item['icon_key'] = $row['icon_key'] === null ? null : (string) $row['icon_key'];
            }
            $items[] = $item;
        }

        return $items;
    }

    /** @return array<string,mixed> */
    private function createInTable(string $table, int $userId, Request $request): array
    {
        $name = $this->validatedName($request);
        $iconKey = $table === 'tags' ? $this->validatedTagIconKey($request) : null;
        $iconFromPayload = $table === 'tags' && array_key_exists('icon_key', $request->json());

        $existingStmt = $this->pdo->prepare(
            $table === 'tags'
                ? "SELECT id, is_active, deleted_at, icon_key FROM {$table} WHERE user_id = :user_id AND name = :name LIMIT 1"
                : "SELECT id, is_active, deleted_at FROM {$table} WHERE user_id = :user_id AND name = :name LIMIT 1"
        );
        $existingStmt->execute([
            ':user_id' => $userId,
            ':name' => $name,
        ]);
        $existing = $existingStmt->fetch();

        if ($existing) {
            if ((int) $existing['is_active'] === 1 && $existing['deleted_at'] === null) {
                throw new HttpException(409, 'CONFLICT', ucfirst(rtrim($table, 's')) . ' already exists');
            }

            $reactivate = $this->pdo->prepare(
                $table === 'tags'
                    ? "UPDATE {$table} SET is_active = 1, deleted_at = NULL, icon_key = :icon_key, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id"
                    : "UPDATE {$table} SET is_active = 1, deleted_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id"
            );
            $reactivateParams = [
                ':id' => $existing['id'],
                ':user_id' => $userId,
            ];
            if ($table === 'tags') {
                $reactivateParams[':icon_key'] = $iconFromPayload ? $iconKey : ($existing['icon_key'] ?? null);
            }
            $reactivate->execute($reactivateParams);

            $response = [
                'id' => (string) $existing['id'],
                'name' => $name,
            ];
            if ($table === 'tags') {
                $response['icon_key'] = $iconFromPayload ? $iconKey : ($existing['icon_key'] === null ? null : (string) $existing['icon_key']);
            }

            return $response;
        }

        try {
            $stmt = $this->pdo->prepare(
                $table === 'tags'
                    ? "INSERT INTO {$table} (user_id, name, icon_key, is_active) VALUES (:user_id, :name, :icon_key, 1)"
                    : "INSERT INTO {$table} (user_id, name, is_active) VALUES (:user_id, :name, 1)"
            );
            $insertParams = [
                ':user_id' => $userId,
                ':name' => $name,
            ];
            if ($table === 'tags') {
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
        if ($table === 'tags') {
            $response['icon_key'] = $iconKey;
        }

        return $response;
    }

    /** @return array<string,mixed> */
    private function updateInTable(string $table, int $userId, int $id, Request $request): array
    {
        $name = $this->validatedName($request);
        $payload = $request->json();
        $iconFromPayload = $table === 'tags' && array_key_exists('icon_key', $payload);
        $iconKey = $table === 'tags' && $iconFromPayload ? $this->validatedTagIconKey($request) : null;

        $exists = $this->pdo->prepare(
            $table === 'tags'
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
                $table === 'tags'
                    ? "UPDATE {$table} SET name = :name, icon_key = :icon_key, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id"
                    : "UPDATE {$table} SET name = :name, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id"
            );
            $params = [
                ':name' => $name,
                ':id' => $id,
                ':user_id' => $userId,
            ];
            if ($table === 'tags') {
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
        if ($table === 'tags') {
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

    private function validatedTagIconKey(Request $request): ?string
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

        if (!in_array($iconKey, self::ALLOWED_TAG_ICON_KEYS, true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'icon_key', 'message' => 'unsupported icon key'],
            ]);
        }

        return $iconKey;
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
}
