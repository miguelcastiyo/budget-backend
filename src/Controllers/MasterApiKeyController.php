<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Core\Config;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\AuditLogger;
use App\Support\Str;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class MasterApiKeyController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthService $auth,
        private readonly AuditLogger $audit,
        private readonly Config $config
    ) {
    }

    public function listKeys(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: false, sessionOnly: true);
        $this->auth->requireRole($ctx, ['owner', 'admin']);

        $stmt = $this->pdo->prepare(
            'SELECT key_id, name, key_prefix, is_active, created_at, last_used_at, expires_at, revoked_at FROM master_api_keys WHERE user_id = :user_id ORDER BY created_at DESC'
        );
        $stmt->execute([':user_id' => $ctx->userId()]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id' => (string) $row['key_id'],
                'name' => (string) $row['name'],
                'key_prefix' => (string) $row['key_prefix'],
                'created_at' => (string) $row['created_at'],
                'last_used_at' => $row['last_used_at'],
                'expires_at' => $row['expires_at'],
                'status' => $this->apiKeyStatus($row),
            ];
        }

        return Response::json(['items' => $items]);
    }

    public function create(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: false, sessionOnly: true);
        $this->auth->requireRole($ctx, ['owner', 'admin']);

        $payload = $request->json();
        $name = trim((string) ($payload['name'] ?? ''));
        $expiresAt = $payload['expires_at'] ?? null;

        if ($name === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'name', 'message' => 'is required'],
            ]);
        }
        if (strlen($name) > 120) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'name', 'message' => 'must be 120 characters or fewer'],
            ]);
        }

        $expiresAt = $this->validatedExpiresAt($expiresAt);

        $keyId = Str::randomId('mak');
        $keyPrefix = 'bgtm_live_' . Str::randomHex(4);
        $keySecret = Str::randomHex(18);
        $apiKey = $keyPrefix . '_' . $keySecret;
        $keyHash = Str::hashSha256($apiKey);

        $sql = 'INSERT INTO master_api_keys (key_id, user_id, name, key_prefix, key_hash, is_active, expires_at) VALUES (:key_id, :user_id, :name, :key_prefix, :key_hash, 1, :expires_at)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':key_id' => $keyId,
            ':user_id' => $ctx->userId(),
            ':name' => $name,
            ':key_prefix' => $keyPrefix,
            ':key_hash' => $keyHash,
            ':expires_at' => $expiresAt,
        ]);

        $lookup = $this->pdo->prepare('SELECT is_active, created_at, last_used_at, expires_at, revoked_at FROM master_api_keys WHERE key_id = :key_id LIMIT 1');
        $lookup->execute([':key_id' => $keyId]);
        $row = $lookup->fetch();

        $this->audit->record(
            $request,
            $ctx->userId(),
            $ctx->authType,
            'master_api_key.created',
            'master_api_key',
            $keyId,
            [
                'name' => $name,
                'key_prefix' => $keyPrefix,
                'expires_at' => $row['expires_at'] ?? null,
            ]
        );

        return Response::json([
            'id' => $keyId,
            'name' => $name,
            'api_key' => $apiKey,
            'key_prefix' => $keyPrefix,
            'created_at' => $row['created_at'] ?? null,
            'last_used_at' => null,
            'expires_at' => $row['expires_at'] ?? null,
            'status' => is_array($row) ? $this->apiKeyStatus($row) : 'active',
        ], 201);
    }

    /** @param array{api_key_id:string} $params */
    public function revoke(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: false, sessionOnly: true);
        $this->auth->requireRole($ctx, ['owner', 'admin']);

        $apiKeyId = trim((string) ($params['api_key_id'] ?? ''));
        if ($apiKeyId === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'api_key_id', 'message' => 'is required'],
            ]);
        }

        $stmt = $this->pdo->prepare(
            'UPDATE master_api_keys SET is_active = 0, revoked_at = UTC_TIMESTAMP() WHERE key_id = :key_id AND user_id = :user_id AND revoked_at IS NULL'
        );
        $stmt->execute([
            ':key_id' => $apiKeyId,
            ':user_id' => $ctx->userId(),
        ]);

        if ($stmt->rowCount() === 0) {
            throw new HttpException(404, 'NOT_FOUND', 'Master API key not found');
        }

        $this->audit->record(
            $request,
            $ctx->userId(),
            $ctx->authType,
            'master_api_key.revoked',
            'master_api_key',
            $apiKeyId
        );

        return Response::noContent();
    }

    private function validatedExpiresAt(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'expires_at', 'message' => 'must be a valid date-time or null'],
            ]);
        }

        try {
            $expiresAt = new DateTimeImmutable($value);
        } catch (\Exception) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'expires_at', 'message' => 'must be a valid date-time or null'],
            ]);
        }

        $expiresAt = $expiresAt->setTimezone(new DateTimeZone('UTC'));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($expiresAt <= $now) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'expires_at', 'message' => 'must be in the future'],
            ]);
        }

        $maxDays = max(1, $this->config->getInt('MASTER_API_KEY_MAX_TTL_DAYS', 365));
        $max = $now->modify('+' . $maxDays . ' days');
        if ($expiresAt > $max) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'expires_at', 'message' => 'must be within ' . $maxDays . ' days'],
            ]);
        }

        return $expiresAt->format('Y-m-d H:i:s');
    }

    /** @param array<string,mixed> $row */
    private function apiKeyStatus(array $row): string
    {
        if ((int) ($row['is_active'] ?? 1) !== 1 || ($row['revoked_at'] ?? null) !== null) {
            return 'revoked';
        }

        $expiresAt = $row['expires_at'] ?? null;
        if (is_string($expiresAt) && trim($expiresAt) !== '') {
            $expiresAtUnix = strtotime($expiresAt . ' UTC');
            if ($expiresAtUnix !== false && $expiresAtUnix <= time()) {
                return 'expired';
            }
        }

        return 'active';
    }
}
