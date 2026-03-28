<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Auth\GoogleTokenVerifier;
use App\Core\Config;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Mail\Mailer;
use App\Support\Str;
use PDO;

final class ProfileController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthService $auth,
        private readonly GoogleTokenVerifier $googleTokens,
        private readonly Mailer $mailer,
        private readonly Config $config
    ) {
    }

    public function getMe(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        return Response::json($this->profileFromAuth($ctx->user));
    }

    public function updateMe(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $payload = $request->json();

        $displayName = trim((string) ($payload['display_name'] ?? ''));
        if ($displayName === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'display_name', 'message' => 'is required'],
            ]);
        }

        $stmt = $this->pdo->prepare('UPDATE users SET display_name = :display_name WHERE id = :id');
        $stmt->execute([
            ':display_name' => $displayName,
            ':id' => $ctx->userId(),
        ]);

        $profile = $this->fetchProfile($ctx->userId());
        return Response::json($profile);
    }

    public function requestEmailChange(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        if ((string) $ctx->user['auth_provider'] !== 'password') {
            throw new HttpException(403, 'FORBIDDEN', 'Email can only be changed for password users');
        }

        $payload = $request->json();
        $newEmail = strtolower(trim((string) ($payload['new_email'] ?? '')));
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'new_email', 'message' => 'must be a valid email'],
            ]);
        }

        $emailExists = $this->pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $emailExists->execute([':email' => $newEmail]);
        if ($emailExists->fetch()) {
            throw new HttpException(409, 'CONFLICT', 'Email already in use');
        }

        $requestId = Str::randomId('emc');
        $verificationCode = Str::randomNumericCode(6);
        $verificationCodeHash = Str::hashSha256($verificationCode);
        $ttlMinutes = $this->config->getInt('EMAIL_CHANGE_CODE_TTL_MINUTES', 15);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($ttlMinutes * 60));

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO email_change_requests (request_id, user_id, new_email, verification_code_hash, status, expires_at) VALUES (:request_id, :user_id, :new_email, :verification_code_hash, 'verification_pending', :expires_at)"
            );

            $stmt->execute([
                ':request_id' => $requestId,
                ':user_id' => $ctx->userId(),
                ':new_email' => $newEmail,
                ':verification_code_hash' => $verificationCodeHash,
                ':expires_at' => $expiresAt,
            ]);

            $subject = 'Verify your updated email';
            $text = implode(PHP_EOL, [
                'Use this verification code to confirm your new email:',
                '',
                $verificationCode,
                '',
                'This code expires in ' . $ttlMinutes . ' minutes.',
            ]);
            $this->mailer->send($newEmail, $subject, $text);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return Response::json([
            'email_change_id' => $requestId,
            'status' => 'verification_pending',
        ], 202);
    }

    public function verifyEmailChange(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        if ((string) $ctx->user['auth_provider'] !== 'password') {
            throw new HttpException(403, 'FORBIDDEN', 'Email can only be changed for password users');
        }

        $payload = $request->json();
        $requestId = trim((string) ($payload['email_change_id'] ?? ''));
        $verificationCode = trim((string) ($payload['verification_code'] ?? ''));

        if ($requestId === '' || !preg_match('/^\d{6}$/', $verificationCode)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed');
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, new_email, verification_code_hash FROM email_change_requests WHERE request_id = :request_id AND user_id = :user_id AND status = 'verification_pending' AND expires_at > UTC_TIMESTAMP() LIMIT 1"
        );
        $stmt->execute([
            ':request_id' => $requestId,
            ':user_id' => $ctx->userId(),
        ]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new HttpException(404, 'NOT_FOUND', 'Email change request not found or expired');
        }

        if (!hash_equals((string) $row['verification_code_hash'], Str::hashSha256($verificationCode))) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Invalid verification code');
        }

        $this->pdo->beginTransaction();
        try {
            $updateUser = $this->pdo->prepare('UPDATE users SET email = :email, email_verified = 1 WHERE id = :id');
            $updateUser->execute([
                ':email' => (string) $row['new_email'],
                ':id' => $ctx->userId(),
            ]);

            $updateReq = $this->pdo->prepare("UPDATE email_change_requests SET status = 'verified', verified_at = UTC_TIMESTAMP() WHERE id = :id");
            $updateReq->execute([':id' => $row['id']]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return Response::json([
            'email' => (string) $row['new_email'],
            'email_verified' => true,
        ]);
    }

    public function convertAccountToGoogle(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: false, sessionOnly: true);
        if ((string) $ctx->user['auth_provider'] !== 'password') {
            throw new HttpException(403, 'FORBIDDEN', 'Only password accounts can be converted to Google sign-in');
        }

        $payload = $request->json();
        $googleIdToken = trim((string) ($payload['google_id_token'] ?? ''));
        if ($googleIdToken === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'google_id_token', 'message' => 'is required'],
            ]);
        }

        $googleIdentity = $this->googleTokens->verifyIdToken($googleIdToken);
        $currentEmail = strtolower((string) $ctx->user['email']);
        $googleEmail = strtolower((string) $googleIdentity['email']);

        if ($currentEmail !== $googleEmail) {
            throw new HttpException(409, 'CONFLICT', 'Google email must match the current account email');
        }

        $existingGoogle = $this->pdo->prepare('SELECT id FROM users WHERE google_sub = :google_sub LIMIT 1');
        $existingGoogle->execute([':google_sub' => (string) $googleIdentity['google_sub']]);
        $existingGoogleUser = $existingGoogle->fetch();
        if ($existingGoogleUser && (int) $existingGoogleUser['id'] !== $ctx->userId()) {
            throw new HttpException(409, 'CONFLICT', 'This Google account is already linked to another user');
        }

        $avatarUrl = $this->normalizeAvatarUrl($googleIdentity['picture'] ?? null);
        $currentAvatarUrl = $ctx->user['avatar_url'] !== null ? (string) $ctx->user['avatar_url'] : null;
        $resolvedAvatarUrl = $avatarUrl ?? $currentAvatarUrl;

        $this->pdo->beginTransaction();
        try {
            $updateUser = $this->pdo->prepare(
                'UPDATE users SET auth_provider = :auth_provider, password_hash = NULL, google_sub = :google_sub, avatar_url = :avatar_url, email_verified = 1 WHERE id = :id'
            );
            $updateUser->execute([
                ':auth_provider' => 'google',
                ':google_sub' => (string) $googleIdentity['google_sub'],
                ':avatar_url' => $resolvedAvatarUrl,
                ':id' => $ctx->userId(),
            ]);

            if ($ctx->sessionId !== null) {
                $revokeOtherSessions = $this->pdo->prepare(
                    'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP() WHERE user_id = :user_id AND session_id <> :session_id AND revoked_at IS NULL'
                );
                $revokeOtherSessions->execute([
                    ':user_id' => $ctx->userId(),
                    ':session_id' => $ctx->sessionId,
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $profile = $this->fetchProfile($ctx->userId());
        return Response::json($profile);
    }

    /** @param array<string,mixed> $user */
    private function profileFromAuth(array $user): array
    {
        $userId = (int) $user['id'];
        $displayName = (string) $user['display_name'];

        return [
            'id' => (string) $user['id'],
            'email' => (string) $user['email'],
            'display_name' => $displayName,
            'auth_provider' => (string) $user['auth_provider'],
            'avatar_url' => $user['avatar_url'] !== null ? (string) $user['avatar_url'] : null,
            'email_verified' => (bool) $user['email_verified'],
            'created_at' => (string) $user['created_at'],
            'onboarding_complete' => $this->isOnboardingComplete($userId, $displayName),
        ];
    }

    /** @return array<string,mixed> */
    private function fetchProfile(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, display_name, auth_provider, avatar_url, email_verified, created_at FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new HttpException(404, 'NOT_FOUND', 'User not found');
        }

        return [
            'id' => (string) $row['id'],
            'email' => (string) $row['email'],
            'display_name' => (string) $row['display_name'],
            'auth_provider' => (string) $row['auth_provider'],
            'avatar_url' => $row['avatar_url'] !== null ? (string) $row['avatar_url'] : null,
            'email_verified' => (bool) $row['email_verified'],
            'created_at' => (string) $row['created_at'],
            'onboarding_complete' => $this->isOnboardingComplete((int) $row['id'], (string) $row['display_name']),
        ];
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
}
