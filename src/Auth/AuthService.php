<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\HttpException;
use App\Http\Request;
use App\Support\Str;
use PDO;

final class AuthService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function requireAuth(Request $request, bool $allowApiKey = true, bool $sessionOnly = false): AuthContext
    {
        $authHeader = (string) ($request->header('Authorization') ?? '');
        $apiKey = (string) ($request->header('X-API-Key') ?? '');

        if (str_starts_with($authHeader, 'Session ')) {
            $token = trim(substr($authHeader, 8));
            return $this->authenticateSessionToken($token, $request, 'header');
        }

        $cookieToken = (string) ($request->cookies['sid'] ?? '');
        if ($cookieToken !== '') {
            return $this->authenticateSessionToken($cookieToken, $request, 'cookie');
        }

        if ($apiKey !== '' && !$allowApiKey) {
            throw new HttpException(403, 'FORBIDDEN', 'This endpoint requires a session');
        }

        if ($allowApiKey) {
            if ($apiKey !== '') {
                if ($sessionOnly) {
                    throw new HttpException(403, 'FORBIDDEN', 'This endpoint requires a session');
                }
                return $this->authenticateApiKey($apiKey);
            }
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

        $sql = <<<'SQL'
SELECT
  us.session_id,
  us.csrf_token_hash,
  u.id,
  u.email,
  u.display_name,
  u.avatar_url,
  u.auth_provider,
  u.email_verified,
  u.role,
  u.created_at
FROM user_sessions us
JOIN users u ON u.id = us.user_id
WHERE us.session_id = :session_id
  AND us.session_secret_hash = :session_secret_hash
  AND us.revoked_at IS NULL
  AND us.expires_at > UTC_TIMESTAMP()
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

        $touch = $this->pdo->prepare('UPDATE user_sessions SET last_seen_at = UTC_TIMESTAMP() WHERE session_id = :session_id');
        $touch->execute([':session_id' => $sessionId]);

        return new AuthContext($row, 'session', $sessionId, null, $source);
    }

    private function authenticateApiKey(string $apiKey): AuthContext
    {
        $hash = Str::hashSha256($apiKey);

        $sql = <<<'SQL'
SELECT
  mak.key_id,
  u.id,
  u.email,
  u.display_name,
  u.avatar_url,
  u.auth_provider,
  u.email_verified,
  u.role,
  u.created_at
FROM master_api_keys mak
JOIN users u ON u.id = mak.user_id
WHERE mak.key_hash = :key_hash
  AND mak.is_active = 1
  AND mak.revoked_at IS NULL
  AND (mak.expires_at IS NULL OR mak.expires_at > UTC_TIMESTAMP())
  AND u.is_active = 1
LIMIT 1
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':key_hash' => $hash]);

        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Master API key is invalid or expired');
        }

        $touch = $this->pdo->prepare('UPDATE master_api_keys SET last_used_at = UTC_TIMESTAMP() WHERE key_id = :key_id');
        $touch->execute([':key_id' => $row['key_id']]);

        return new AuthContext($row, 'api_key', null, (string) $row['key_id']);
    }

    private function isCsrfProtectedMethod(string $method): bool
    {
        $method = strtoupper($method);
        return !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
    }
}
