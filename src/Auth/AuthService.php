<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Http\HttpException;
use App\Http\Request;
use App\Support\Str;
use PDO;

final class AuthService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config
    )
    {
    }

    public function requireAuth(Request $request): AuthContext
    {
        $authHeader = (string) ($request->header('Authorization') ?? '');

        if (str_starts_with($authHeader, 'Session ')) {
            $token = trim(substr($authHeader, 8));
            return $this->authenticateSessionToken($token, $request, 'header');
        }

        $cookieToken = (string) ($request->cookies['sid'] ?? '');
        if ($cookieToken !== '') {
            return $this->authenticateSessionToken($cookieToken, $request, 'cookie');
        }

        throw new HttpException(401, 'UNAUTHENTICATED', 'Authentication required');
    }

    public function requireRole(AuthContext $auth, array $roles): void
    {
        if (!in_array($auth->role(), $roles, true)) {
            throw new HttpException(403, 'FORBIDDEN', 'Insufficient role');
        }
    }

    private function authenticateSessionToken(string $token, Request $request, string $source): AuthContext
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Invalid session token format');
        }

        [$sessionId, $sessionSecret] = $parts;
        $sessionSecretHash = Str::hashSha256($sessionSecret);

        $sessionCreatedAt = $this->sessionCreatedAtExpression();
        $sql = <<<SQL
SELECT
  us.session_id,
  {$this->sessionDeviceIdExpression()},
  us.csrf_token_hash,
  us.last_seen_at,
  {$sessionCreatedAt},
  u.id,
  u.email,
  u.display_name,
  u.avatar_url,
  u.user_preferences,
  u.auth_provider,
  u.email_verified,
  u.role,
  u.created_at
FROM user_sessions us
JOIN users u ON u.id = us.user_id
WHERE us.session_id = :session_id
  AND us.session_secret_hash = :session_secret_hash
  AND us.revoked_at IS NULL
  AND us.expires_at > CURRENT_TIMESTAMP
  AND u.is_active = 1
LIMIT 1
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':session_id' => $sessionId,
            ':session_secret_hash' => $sessionSecretHash,
        ]);

        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Session is invalid or expired');
        }

        if ($source === 'cookie' && $this->isCsrfProtectedMethod($request->method)) {
            $providedCsrfToken = trim((string) ($request->header('X-CSRF-Token') ?? ''));
            if ($providedCsrfToken === '') {
                throw new HttpException(403, 'FORBIDDEN', 'Missing CSRF token');
            }

            $csrfTokenHash = (string) ($row['csrf_token_hash'] ?? '');
            if ($csrfTokenHash === '' || !hash_equals($csrfTokenHash, Str::hashSha256($providedCsrfToken))) {
                throw new HttpException(403, 'FORBIDDEN', 'Invalid CSRF token');
            }
        }

        if ($this->shouldTouchSession($row['last_seen_at'] ?? null)) {
            $touch = $this->pdo->prepare('UPDATE user_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE session_id = :session_id');
            $touch->execute([':session_id' => $sessionId]);
        }

        return new AuthContext(
            $row,
            'session',
            $sessionId,
            $source,
            (string) ($row['device_id'] ?? '')
        );
    }

    private function isCsrfProtectedMethod(string $method): bool
    {
        $method = strtoupper($method);
        return !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    private function shouldTouchSession(mixed $lastSeenAt): bool
    {
        if (!is_string($lastSeenAt) || trim($lastSeenAt) === '') {
            return true;
        }

        $lastSeenUnix = strtotime($lastSeenAt . ' UTC');
        if ($lastSeenUnix === false) {
            return true;
        }

        $intervalSeconds = max(0, $this->config->getInt('SESSION_LAST_SEEN_UPDATE_INTERVAL_SECONDS', 300));
        if ($intervalSeconds === 0) {
            return true;
        }

        return $lastSeenUnix <= (time() - $intervalSeconds);
    }

    private function sessionCreatedAtExpression(): string
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            return 'us.created_at AS session_created_at';
        }

        $columns = $this->pdo->query('PRAGMA table_info(user_sessions)')->fetchAll();
        foreach ($columns as $column) {
            if ((string) ($column['name'] ?? '') === 'created_at') {
                return 'us.created_at AS session_created_at';
            }
        }

        return 'NULL AS session_created_at';
    }

    private function sessionDeviceIdExpression(): string
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') return 'us.device_id AS device_id';
        $columns = $this->pdo->query('PRAGMA table_info(user_sessions)')->fetchAll();
        foreach ($columns as $column) if ((string) ($column['name'] ?? '') === 'device_id') return 'us.device_id AS device_id';
        return 'NULL AS device_id';
    }
}
