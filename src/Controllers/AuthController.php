<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Auth\GoogleTokenVerifier;
use App\Core\Config;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Mail\InviteEmailTemplate;
use App\Mail\Mailer;
use App\Support\Str;
use PDO;

final class AuthController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthService $auth,
        private readonly GoogleTokenVerifier $googleTokens,
        private readonly Mailer $mailer,
        private readonly Config $config
    ) {
    }

    public function createInvitation(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: false, sessionOnly: true);
        $this->auth->requireRole($ctx, ['owner', 'admin']);

        $payload = $request->json();
        $email = trim((string) ($payload['email'] ?? ''));
        $authMethod = (string) ($payload['auth_method'] ?? '');
        $expiresInDays = (int) ($payload['expires_in_days'] ?? 0);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'email', 'message' => 'must be a valid email'],
            ]);
        }
        if ($authMethod !== 'google_or_password') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'auth_method', 'message' => 'must be google_or_password'],
            ]);
        }
        if ($expiresInDays < 1 || $expiresInDays > 30) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'expires_in_days', 'message' => 'must be between 1 and 30'],
            ]);
        }

        $inviteId = Str::randomId('inv');
        $inviteToken = Str::randomHex(24);
        $inviteTokenHash = Str::hashSha256($inviteToken);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($expiresInDays * 86400));

        $sql = <<<'SQL'
INSERT INTO invitations (
  invite_id,
  invite_token_hash,
  email,
  auth_method,
  invited_by_user_id,
  status,
  expires_at
)
VALUES (
  :invite_id,
  :invite_token_hash,
  :email,
  :auth_method,
  :invited_by_user_id,
  'pending',
  :expires_at
)
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':invite_id' => $inviteId,
            ':invite_token_hash' => $inviteTokenHash,
            ':email' => strtolower($email),
            ':auth_method' => $authMethod,
            ':invited_by_user_id' => $ctx->userId(),
            ':expires_at' => $expiresAt,
        ]);

        try {
            $this->sendInviteEmail(
                toEmail: strtolower($email),
                inviteToken: $inviteToken,
                expiresAt: $expiresAt
            );
        } catch (\Throwable $e) {
            $cleanup = $this->pdo->prepare('DELETE FROM invitations WHERE invite_id = :invite_id');
            $cleanup->execute([':invite_id' => $inviteId]);
            throw $e;
        }

        return Response::json([
            'invite_id' => $inviteId,
            'email' => strtolower($email),
            'status' => 'pending',
            'expires_at' => $expiresAt,
        ], 201);
    }

    public function acceptInvitationPassword(Request $request): Response
    {
        $payload = $request->json();

        $inviteToken = trim((string) ($payload['invite_token'] ?? ''));
        $displayName = trim((string) ($payload['display_name'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $clientType = (string) ($payload['client_type'] ?? 'web');

        if ($inviteToken === '' || $displayName === '' || strlen($password) < 8) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed');
        }
        if (!in_array($clientType, ['web', 'native'], true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'client_type', 'message' => 'must be web or native'],
            ]);
        }

        $invitation = $this->getActiveInvitationByToken($inviteToken);
        $email = (string) $invitation['email'];

        $exists = $this->pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $exists->execute([':email' => $email]);
        if ($exists->fetch()) {
            throw new HttpException(409, 'CONFLICT', 'User already exists for this invitation email');
        }

        $this->pdo->beginTransaction();
        try {
            $insertUser = $this->pdo->prepare(
                'INSERT INTO users (email, display_name, auth_provider, password_hash, email_verified, role) VALUES (:email, :display_name, :auth_provider, :password_hash, 1, :role)'
            );
            $insertUser->execute([
                ':email' => $email,
                ':display_name' => $displayName,
                ':auth_provider' => 'password',
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':role' => 'member',
            ]);

            $userId = (int) $this->pdo->lastInsertId();
            $this->markInvitationAccepted((int) $invitation['id'], $userId);

            $session = $this->createSession($userId, $clientType, $request);

            $this->pdo->commit();

            return $this->buildAuthResponse($userId, $session, $clientType, 201);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function acceptInvitationGoogle(Request $request): Response
    {
        $payload = $request->json();

        $inviteToken = trim((string) ($payload['invite_token'] ?? ''));
        $googleIdToken = trim((string) ($payload['google_id_token'] ?? ''));
        $displayName = trim((string) ($payload['display_name'] ?? ''));
        $clientType = (string) ($payload['client_type'] ?? 'web');

        if ($inviteToken === '' || $googleIdToken === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed');
        }
        if (!in_array($clientType, ['web', 'native'], true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'client_type', 'message' => 'must be web or native'],
            ]);
        }

        $googleIdentity = $this->googleTokens->verifyIdToken($googleIdToken);
        $invitation = $this->getActiveInvitationByToken($inviteToken);
        $googleAvatarUrl = $this->normalizeAvatarUrl($googleIdentity['picture'] ?? null);

        if (strtolower((string) $invitation['email']) !== strtolower($googleIdentity['email'])) {
            throw new HttpException(409, 'CONFLICT', 'Google email does not match invite email');
        }

        $exists = $this->pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $exists->execute([':email' => strtolower($googleIdentity['email'])]);
        if ($exists->fetch()) {
            throw new HttpException(409, 'CONFLICT', 'User already exists for this invitation email');
        }

        $resolvedDisplayName = $displayName !== ''
            ? $displayName
            : $this->resolveGoogleDisplayName(
                $googleIdentity['name'] ?? null,
                strtolower((string) $googleIdentity['email'])
            );

        $this->pdo->beginTransaction();
        try {
            $insertUser = $this->pdo->prepare(
                'INSERT INTO users (email, display_name, auth_provider, google_sub, avatar_url, email_verified, role) VALUES (:email, :display_name, :auth_provider, :google_sub, :avatar_url, 1, :role)'
            );
            $insertUser->execute([
                ':email' => strtolower($googleIdentity['email']),
                ':display_name' => $resolvedDisplayName,
                ':auth_provider' => 'google',
                ':google_sub' => $googleIdentity['google_sub'],
                ':avatar_url' => $googleAvatarUrl,
                ':role' => 'member',
            ]);

            $userId = (int) $this->pdo->lastInsertId();
            $this->markInvitationAccepted((int) $invitation['id'], $userId);

            $session = $this->createSession($userId, $clientType, $request);

            $this->pdo->commit();

            return $this->buildAuthResponse($userId, $session, $clientType, 201);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function signInPassword(Request $request): Response
    {
        $payload = $request->json();

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');
        $clientType = (string) ($payload['client_type'] ?? 'web');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed');
        }
        if (!in_array($clientType, ['web', 'native'], true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'client_type', 'message' => 'must be web or native'],
            ]);
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, password_hash FROM users WHERE email = :email AND auth_provider = :auth_provider AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([
            ':email' => $email,
            ':auth_provider' => 'password',
        ]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Invalid email or password');
        }

        $session = $this->createSession((int) $user['id'], $clientType, $request);
        return $this->buildAuthResponse((int) $user['id'], $session, $clientType);
    }

    public function signInGoogle(Request $request): Response
    {
        $payload = $request->json();

        $googleIdToken = trim((string) ($payload['google_id_token'] ?? ''));
        $inviteToken = trim((string) ($payload['invite_token'] ?? ''));
        $clientType = (string) ($payload['client_type'] ?? 'web');

        if ($googleIdToken === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'google_id_token', 'message' => 'is required'],
            ]);
        }
        if (!in_array($clientType, ['web', 'native'], true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'client_type', 'message' => 'must be web or native'],
            ]);
        }

        $googleIdentity = $this->googleTokens->verifyIdToken($googleIdToken);
        $googleAvatarUrl = $this->normalizeAvatarUrl($googleIdentity['picture'] ?? null);

        $stmt = $this->pdo->prepare(
            'SELECT id FROM users WHERE email = :email AND auth_provider = :auth_provider AND google_sub = :google_sub AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([
            ':email' => strtolower($googleIdentity['email']),
            ':auth_provider' => 'google',
            ':google_sub' => $googleIdentity['google_sub'],
        ]);

        $user = $stmt->fetch();
        if ($user) {
            $this->syncGoogleAvatarUrl((int) $user['id'], $googleAvatarUrl);
            $session = $this->createSession((int) $user['id'], $clientType, $request);
            return $this->buildAuthResponse((int) $user['id'], $session, $clientType);
        }

        if ($inviteToken === '') {
            throw new HttpException(401, 'UNAUTHENTICATED', 'User must be invited before signing in');
        }

        $invitation = $this->getActiveInvitationByToken($inviteToken);
        if (strtolower((string) $invitation['email']) !== strtolower((string) $googleIdentity['email'])) {
            throw new HttpException(409, 'CONFLICT', 'Google email does not match invite email');
        }

        $existingByEmail = $this->pdo->prepare('SELECT id, auth_provider FROM users WHERE email = :email LIMIT 1');
        $existingByEmail->execute([':email' => strtolower((string) $googleIdentity['email'])]);
        $row = $existingByEmail->fetch();
        if ($row) {
            if ((string) $row['auth_provider'] !== 'google') {
                throw new HttpException(409, 'CONFLICT', 'An account already exists for this email');
            }

            $this->syncGoogleAvatarUrl((int) $row['id'], $googleAvatarUrl);
            $this->markInvitationAccepted((int) $invitation['id'], (int) $row['id']);
            $session = $this->createSession((int) $row['id'], $clientType, $request);
            return $this->buildAuthResponse((int) $row['id'], $session, $clientType);
        }

        $this->pdo->beginTransaction();
        try {
            $insertUser = $this->pdo->prepare(
                'INSERT INTO users (email, display_name, auth_provider, google_sub, avatar_url, email_verified, role) VALUES (:email, :display_name, :auth_provider, :google_sub, :avatar_url, 1, :role)'
            );
            $insertUser->execute([
                ':email' => strtolower((string) $googleIdentity['email']),
                ':display_name' => '',
                ':auth_provider' => 'google',
                ':google_sub' => (string) $googleIdentity['google_sub'],
                ':avatar_url' => $googleAvatarUrl,
                ':role' => 'member',
            ]);

            $userId = (int) $this->pdo->lastInsertId();
            $this->markInvitationAccepted((int) $invitation['id'], $userId);
            $session = $this->createSession($userId, $clientType, $request);

            $this->pdo->commit();

            return $this->buildAuthResponse($userId, $session, $clientType, 201);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function signOutCurrentSession(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);

        if ($ctx->authType === 'session' && $ctx->sessionId !== null) {
            $stmt = $this->pdo->prepare('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP() WHERE session_id = :session_id');
            $stmt->execute([':session_id' => $ctx->sessionId]);
        }

        $response = Response::noContent();
        if ($ctx->authType === 'session') {
            $response = $response->withHeader('Set-Cookie', sprintf('sid=; %s; Max-Age=0', $this->sessionCookieAttributes()));
        }

        return $response;
    }

    /** @return array<string,mixed> */
    private function getActiveInvitationByToken(string $inviteToken): array
    {
        $hash = Str::hashSha256($inviteToken);

        $stmt = $this->pdo->prepare(
            "SELECT id, email FROM invitations WHERE invite_token_hash = :token_hash AND status = 'pending' AND expires_at > UTC_TIMESTAMP() LIMIT 1"
        );
        $stmt->execute([':token_hash' => $hash]);
        $invitation = $stmt->fetch();

        if (!$invitation) {
            throw new HttpException(404, 'NOT_FOUND', 'Invitation not found or expired');
        }

        return $invitation;
    }

    private function markInvitationAccepted(int $invitationRowId, int $userId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE invitations SET status = 'accepted', accepted_by_user_id = :user_id, accepted_at = UTC_TIMESTAMP() WHERE id = :id"
        );
        $stmt->execute([
            ':id' => $invitationRowId,
            ':user_id' => $userId,
        ]);
    }

    /** @return array{session_id:string,expires_at:string,token:string,csrf_token:string} */
    private function createSession(int $userId, string $clientType, Request $request): array
    {
        $sessionId = Str::randomId('ses');
        $secret = Str::randomHex(20);
        $sessionToken = $sessionId . '.' . $secret;
        $sessionSecretHash = Str::hashSha256($secret);
        $csrfToken = Str::randomHex(20);
        $csrfTokenHash = Str::hashSha256($csrfToken);
        $ttlHours = $this->config->getInt('SESSION_TTL_HOURS', 168);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($ttlHours * 3600));

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_sessions (session_id, user_id, session_secret_hash, csrf_token_hash, client_type, ip_address, user_agent, last_seen_at, expires_at) VALUES (:session_id, :user_id, :session_secret_hash, :csrf_token_hash, :client_type, :ip_address, :user_agent, UTC_TIMESTAMP(), :expires_at)'
        );

        $stmt->execute([
            ':session_id' => $sessionId,
            ':user_id' => $userId,
            ':session_secret_hash' => $sessionSecretHash,
            ':csrf_token_hash' => $csrfTokenHash,
            ':client_type' => $clientType,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent' => substr((string) ($request->header('User-Agent') ?? ''), 0, 255),
            ':expires_at' => $expiresAt,
        ]);

        $lookup = $this->pdo->prepare('SELECT expires_at FROM user_sessions WHERE session_id = :session_id LIMIT 1');
        $lookup->execute([':session_id' => $sessionId]);
        $row = $lookup->fetch();

        return [
            'session_id' => $sessionId,
            'expires_at' => (string) ($row['expires_at'] ?? ''),
            'token' => $sessionToken,
            'csrf_token' => $csrfToken,
        ];
    }

    private function buildAuthResponse(int $userId, array $session, string $clientType, int $statusCode = 200): Response
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, display_name, auth_provider, avatar_url FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new HttpException(500, 'INTERNAL_ERROR', 'User was not found after authentication');
        }

        $body = [
            'user' => [
                'id' => (string) $user['id'],
                'email' => (string) $user['email'],
                'display_name' => (string) $user['display_name'],
                'auth_provider' => (string) $user['auth_provider'],
                'avatar_url' => $user['avatar_url'] !== null ? (string) $user['avatar_url'] : null,
                'onboarding_complete' => $this->isOnboardingComplete($userId, (string) $user['display_name']),
            ],
            'session' => [
                'session_id' => $session['session_id'],
                'expires_at' => $session['expires_at'],
                'csrf_token' => $session['csrf_token'],
            ],
        ];

        $response = Response::json($body, $statusCode);

        if ($clientType === 'native') {
            $body['session']['session_token'] = $session['token'];
            return Response::json($body, $statusCode);
        }

        $cookie = sprintf(
            'sid=%s; %s; Max-Age=%d',
            $session['token'],
            $this->sessionCookieAttributes(),
            $this->config->getInt('SESSION_TTL_HOURS', 168) * 3600
        );
        return $response->withHeader('Set-Cookie', $cookie);
    }

    private function sendInviteEmail(string $toEmail, string $inviteToken, string $expiresAt): void
    {
        $appUrl = rtrim((string) $this->config->get('APP_URL', 'http://localhost:8000'), '/');
        $inviteUrl = $appUrl . '/sign-in?invite_token=' . rawurlencode($inviteToken);

        $subject = 'You are invited to Budget App';
        $text = implode(PHP_EOL, [
            'You were invited to Budget App Project.',
            '',
            'Accept invitation: ' . $inviteUrl,
            'Expires at (UTC): ' . $expiresAt,
            '',
            'If this was not expected, you can ignore this email.',
        ]);

        $html = InviteEmailTemplate::render($inviteUrl, $expiresAt);

        $this->mailer->send($toEmail, $subject, $text, $html);
    }

    private function resolveGoogleDisplayName(?string $googleName, string $email): string
    {
        $candidate = trim((string) ($googleName ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }

        $localPart = explode('@', $email, 2)[0] ?? '';
        $normalized = preg_replace('/[._-]+/', ' ', $localPart);
        $normalized = trim((string) $normalized);
        if ($normalized === '') {
            return 'Budget User';
        }

        return ucwords($normalized);
    }

    private function syncGoogleAvatarUrl(int $userId, ?string $avatarUrl): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET avatar_url = :avatar_url WHERE id = :id');
        $stmt->execute([
            ':avatar_url' => $avatarUrl,
            ':id' => $userId,
        ]);
    }

    private function normalizeAvatarUrl(?string $avatarUrl): ?string
    {
        if ($avatarUrl === null) {
            return null;
        }

        $candidate = trim($avatarUrl);
        if ($candidate === '' || strlen($candidate) > 512) {
            return null;
        }

        if (!filter_var($candidate, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $candidate;
    }

    private function isOnboardingComplete(int $userId, string $displayName): bool
    {
        if (trim($displayName) === '') {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT monthly_income FROM budget_settings WHERE user_id = :user_id LIMIT 1');
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch();

        if (!$row) {
            return false;
        }

        return (float) $row['monthly_income'] > 0;
    }

    private function sessionCookieAttributes(): string
    {
        $parts = ['Path=/', 'HttpOnly', 'SameSite=Lax'];
        if ($this->shouldUseSecureCookies()) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    private function shouldUseSecureCookies(): bool
    {
        $override = strtolower(trim((string) $this->config->get('SESSION_COOKIE_SECURE', '')));
        if (in_array($override, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($override, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return strtolower(trim((string) $this->config->get('APP_ENV', 'local'))) === 'production';
    }

}
