<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Auth\AuthContext;
use App\Core\Config;
use App\Http\HttpException;
use DateTimeImmutable;
use DateTimeZone;

final class RecentAuthGuard
{
    public function __construct(private readonly Config $config)
    {
    }

    public function requireRecentInteractiveSession(AuthContext $auth): void
    {
        if ($auth->authType !== 'session' || $auth->sessionId === null) {
            throw new HttpException(403, 'RECENT_AUTH_REQUIRED', 'Recent interactive authentication is required');
        }

        $authenticatedAt = (string) ($auth->user['session_last_authenticated_at'] ?? $auth->user['session_created_at'] ?? '');
        $created = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $authenticatedAt, new DateTimeZone('UTC'));
        $window = max(1, $this->config->getInt('RECENT_AUTH_WINDOW_SECONDS', 900));
        if ($created === false || $created->getTimestamp() < time() - $window) {
            throw new HttpException(403, 'RECENT_AUTH_REQUIRED', 'Recent interactive authentication is required');
        }
    }
}
