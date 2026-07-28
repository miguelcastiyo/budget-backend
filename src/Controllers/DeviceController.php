<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Privacy\RecentAuthGuard;
use PDO;

final class DeviceController
{
    public function __construct(private readonly PDO $pdo, private readonly AuthService $auth, private readonly RecentAuthGuard $recentAuth)
    {
    }

    public function list(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: false, sessionOnly: true);
        $stmt = $this->pdo->prepare('SELECT session_id, client_type, user_agent, created_at, last_seen_at, expires_at, revoked_at FROM user_sessions WHERE user_id = :user_id ORDER BY COALESCE(last_seen_at, created_at) DESC, id DESC');
        $stmt->execute([':user_id' => $ctx->userId()]);
        $items = array_map(static function (array $row) use ($ctx): array {
            return ['id' => (string) $row['session_id'], 'client_type' => (string) $row['client_type'], 'label' => self::label((string) ($row['user_agent'] ?? ''), (string) $row['client_type']), 'created_at' => (string) $row['created_at'], 'last_seen_at' => $row['last_seen_at'] === null ? null : (string) $row['last_seen_at'], 'expires_at' => (string) $row['expires_at'], 'revoked_at' => $row['revoked_at'] === null ? null : (string) $row['revoked_at'], 'is_current' => $ctx->sessionId === (string) $row['session_id']];
        }, $stmt->fetchAll());
        return Response::json(['items' => $items]);
    }

    public function revoke(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: false, sessionOnly: true);
        $this->recentAuth->requireRecentInteractiveSession($ctx);
        $id = (string) ($params['session_id'] ?? '');
        if ($id === '') throw new HttpException(422, 'VALIDATION_ERROR', 'Session id is required');
        $stmt = $this->pdo->prepare('UPDATE user_sessions SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()) WHERE user_id = :user_id AND session_id = :session_id');
        $stmt->execute([':user_id' => $ctx->userId(), ':session_id' => $id]);
        if ($stmt->rowCount() === 0) throw new HttpException(404, 'SESSION_NOT_FOUND', 'Session was not found');
        return Response::json(['revoked' => true, 'session_id' => $id, 'current_device' => $ctx->sessionId === $id]);
    }

    private static function label(string $userAgent, string $clientType): string
    {
        if ($clientType === 'native') return 'Native app';
        if (stripos($userAgent, 'Safari') !== false && stripos($userAgent, 'Chrome') === false) return stripos($userAgent, 'iPhone') !== false ? 'Safari on iPhone' : 'Safari';
        if (stripos($userAgent, 'Chrome') !== false) return 'Chrome on desktop';
        return 'Web browser';
    }
}
