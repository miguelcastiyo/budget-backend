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
use App\Security\AuditLogger;
use App\Support\Str;
use PDO;

final class AuthController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthService $auth,
        private readonly GoogleTokenVerifier $googleTokens,
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly AuditLogger $audit
    ) {
    }

    public function createInvitation(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: false, sessionOnly: true);
        $this->auth->requireRole($ctx, ['owner']);

        $payload = $request->json();
        $inviteeName = trim((string) ($payload['invitee_name'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $role = (string) ($payload['role'] ?? '');
        $expiresAtInput = trim((string) ($payload['expires_at'] ?? ''));
        $emailSubject = trim((string) ($payload['email_subject'] ?? ''));
        $emailBody = trim((string) ($payload['email_body'] ?? ''));
        $authMethod = 'google_or_password';

        if ($inviteeName === '' || strlen($inviteeName) > 120) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'invitee_name', 'message' => 'is required and must be 120 characters or fewer'],
            ]);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'email', 'message' => 'must be a valid email'],
            ]);
        }
        if (!in_array($role, ['admin', 'member'], true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'role', 'message' => 'must be admin or member'],
            ]);
        }
        if ($emailSubject === '' || strlen($emailSubject) > 160) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'email_subject', 'message' => 'is required and must be 160 characters or fewer'],
            ]);
        }
        if ($emailBody === '' || strlen($emailBody) > 5000) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'email_body', 'message' => 'is required and must be 5000 characters or fewer'],
            ]);
        }

        $expiresAt = $this->parseInviteExpiry($expiresAtInput);

        $inviteId = Str::randomId('inv');
        $inviteToken = Str::randomHex(24);
        $inviteTokenHash = Str::hashSha256($inviteToken);

        $sql = <<<'SQL'
INSERT INTO invitations (
  invite_id,
  invite_token_hash,
  invitee_name,
  email,
  role,
  auth_method,
  invited_by_user_id,
  email_subject,
  email_body,
  status,
  expires_at
)
VALUES (
  :invite_id,
  :invite_token_hash,
  :invitee_name,
  :email,
  :role,
  :auth_method,
  :invited_by_user_id,
  :email_subject,
  :email_body,
  'pending',
  :expires_at
)
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':invite_id' => $inviteId,
            ':invite_token_hash' => $inviteTokenHash,
            ':invitee_name' => $inviteeName,
            ':email' => strtolower($email),
            ':role' => $role,
            ':auth_method' => $authMethod,
            ':invited_by_user_id' => $ctx->userId(),
            ':email_subject' => $emailSubject,
            ':email_body' => $emailBody,
            ':expires_at' => $expiresAt,
        ]);

        try {
            $this->sendInviteEmail(
                toEmail: strtolower($email),
                inviteToken: $inviteToken,
                expiresAt: $expiresAt,
                inviteeName: $inviteeName,
                subject: $emailSubject,
                body: $emailBody
            );
        } catch (\Throwable $e) {
            $cleanup = $this->pdo->prepare('DELETE FROM invitations WHERE invite_id = :invite_id');
            $cleanup->execute([':invite_id' => $inviteId]);
            throw $e;
        }

        $this->audit->record(
            $request,
            $ctx->userId(),
            $ctx->authType,
            'invitation.created',
            'invitation',
            $inviteId,
            [
                'email' => strtolower($email),
                'role' => $role,
                'invitee_name' => $inviteeName,
                'expires_at' => $expiresAt,
            ]
        );

        return Response::json([
            'invite_id' => $inviteId,
            'invitee_name' => $inviteeName,
            'email' => strtolower($email),
            'role' => $role,
            'status' => 'pending',
            'expires_at' => $expiresAt,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'accepted_at' => null,
        ], 201);
    }

    public function listInvitations(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: false, sessionOnly: true);
        $this->auth->requireRole($ctx, ['owner']);

        $stmt = $this->pdo->prepare(
            "SELECT i.invite_id, i.invitee_name, i.email, i.role, i.status, i.expires_at, i.accepted_at, i.created_at,
                    i.accepted_by_user_id, u.display_name AS accepted_user_name, u.role AS accepted_user_role,
                    u.is_active AS accepted_user_active
             FROM invitations i
             LEFT JOIN users u ON u.id = i.accepted_by_user_id
             WHERE i.invited_by_user_id = :owner_id
             ORDER BY i.created_at DESC, i.id DESC"
        );
        $stmt->execute([':owner_id' => $ctx->userId()]);
        $rows = $stmt->fetchAll();

        $items = array_map(fn(array $row): array => [
            'invite_id' => (string) $row['invite_id'],
            'invitee_name' => (string) $row['invitee_name'],
            'email' => (string) $row['email'],
            'role' => (string) $row['role'],
            'status' => $this->invitationStatus($row),
            'expires_at' => (string) $row['expires_at'],
            'created_at' => (string) $row['created_at'],
            'accepted_at' => $row['accepted_at'] !== null ? (string) $row['accepted_at'] : null,
            'accepted_user_id' => $row['accepted_by_user_id'] !== null ? (string) $row['accepted_by_user_id'] : null,
            'accepted_user_name' => $row['accepted_user_name'] !== null ? (string) $row['accepted_user_name'] : null,
            'accepted_user_role' => $row['accepted_user_role'] !== null ? (string) $row['accepted_user_role'] : null,
            'accepted_user_active' => $row['accepted_user_active'] !== null ? (bool) $row['accepted_user_active'] : null,
        ], $rows);

        return Response::json(['items' => $items]);
    }

    /** @param array{invite_id:string} $params */
    public function revokeInvitation(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: false, sessionOnly: true);
        $this->auth->requireRole($ctx, ['owner']);

        $inviteId = trim((string) ($params['invite_id'] ?? ''));
        if ($inviteId === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'invite_id', 'message' => 'is required'],
            ]);
        }

        $lookup = $this->pdo->prepare(
            'SELECT invite_id, email, role, status, expires_at FROM invitations WHERE invite_id = :invite_id LIMIT 1'
        );
        $lookup->execute([':invite_id' => $inviteId]);
        $invitation = $lookup->fetch();

        if (!$invitation) {
            throw new HttpException(404, 'NOT_FOUND', 'Invitation not found');
        }

        $status = $this->invitationStatus($invitation);
        if ($status !== 'pending') {
            throw new HttpException(404, 'NOT_FOUND', 'Invitation not found');
        }

        $stmt = $this->pdo->prepare(
            "UPDATE invitations SET status = 'revoked' WHERE invite_id = :invite_id AND status = 'pending' AND expires_at > UTC_TIMESTAMP()"
        );
        $stmt->execute([':invite_id' => $inviteId]);

        if ($stmt->rowCount() === 0) {
            throw new HttpException(404, 'NOT_FOUND', 'Invitation not found');
        }

        $this->audit->record(
            $request,
            $ctx->userId(),
            $ctx->authType,
            'invitation.revoked',
            'invitation',
            $inviteId,
            [
                'email' => (string) $invitation['email'],
                'role' => (string) $invitation['role'],
                'prior_status' => $status,
            ]
        );

        return Response::noContent();
    }

    /** @param array{invite_id:string} $params */
    public function deleteInvitedAccount(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: false, sessionOnly: true);
        $this->auth->requireRole($ctx, ['owner']);

        $inviteId = trim((string) ($params['invite_id'] ?? ''));
        if ($inviteId === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'invite_id', 'message' => 'is required'],
            ]);
        }

        $lookup = $this->pdo->prepare(
            "SELECT i.id, i.invite_id, i.email, i.status, i.accepted_by_user_id,
                    u.role AS accepted_user_role, u.is_active AS accepted_user_active
             FROM invitations i
             LEFT JOIN users u ON u.id = i.accepted_by_user_id
             WHERE i.invite_id = :invite_id AND i.invited_by_user_id = :owner_id
             LIMIT 1
             FOR UPDATE"
        );

        $this->pdo->beginTransaction();
        try {
            $lookup->execute([':invite_id' => $inviteId, ':owner_id' => $ctx->userId()]);
            $invitation = $lookup->fetch();

            if (!$invitation || (string) $invitation['status'] !== 'accepted' || $invitation['accepted_by_user_id'] === null) {
                throw new HttpException(404, 'NOT_FOUND', 'Invited account not found');
            }

            $acceptedUserId = (int) $invitation['accepted_by_user_id'];
            if ((string) ($invitation['accepted_user_role'] ?? '') === 'owner') {
                throw new HttpException(403, 'FORBIDDEN', 'Owner accounts cannot be deleted from invites');
            }

            if ((int) ($invitation['accepted_user_active'] ?? 0) === 0) {
                throw new HttpException(404, 'NOT_FOUND', 'Invited account not found');
            }

            $revokeSessions = $this->pdo->prepare('UPDATE user_sessions SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()) WHERE user_id = :user_id AND revoked_at IS NULL');
            $revokeSessions->execute([':user_id' => $acceptedUserId]);

            $revokeKeys = $this->pdo->prepare("UPDATE master_api_keys SET is_active = 0, revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()) WHERE user_id = :user_id AND is_active = 1 AND revoked_at IS NULL");
            $revokeKeys->execute([':user_id' => $acceptedUserId]);

            $deactivate = $this->pdo->prepare('UPDATE users SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :user_id AND role <> \'owner\' AND is_active = 1');
            $deactivate->execute([':user_id' => $acceptedUserId]);
            if ($deactivate->rowCount() !== 1) {
                throw new HttpException(409, 'CONFLICT', 'Invited account could not be deleted');
            }

            $this->audit->record(
                $request,
                $ctx->userId(),
                $ctx->authType,
                'invited_account.deactivated',
                'user',
                (string) $acceptedUserId,
                [
                    'invite_id' => $inviteId,
                    'email' => (string) $invitation['email'],
                ]
            );

            $this->pdo->commit();
            return Response::noContent();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function previewInvitation(Request $request): Response
    {
        $inviteToken = trim((string) ($request->query['invite_token'] ?? ''));

        if ($inviteToken === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'invite_token is required', [
                ['field' => 'invite_token', 'message' => 'is required'],
            ]);
        }

        $invitation = $this->getActiveInvitationByToken($inviteToken);
        $email = (string) $invitation['email'];

        return Response::json([
            'invite_id' => (string) $invitation['invite_id'],
            'invitee_name' => (string) $invitation['invitee_name'],
            'email' => $email,
            'preferred_auth_provider' => $this->preferredInviteAuthProvider($email),
        ]);
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
                'INSERT INTO users (email, display_name, auth_provider, password_hash, email_verified, role, financial_privacy_state) VALUES (:email, :display_name, :auth_provider, :password_hash, 1, :role, \'vault_setup_required\')'
            );
            $insertUser->execute([
                ':email' => $email,
                ':display_name' => $displayName,
                ':auth_provider' => 'password',
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':role' => (string) $invitation['role'],
            ]);

            $userId = (int) $this->pdo->lastInsertId();
            $this->markInvitationAccepted((int) $invitation['id'], $userId);
            $this->audit->record(
                $request,
                $userId,
                'session',
                'invitation.accepted',
                'invitation',
                (string) $invitation['invite_id'],
                [
                    'accepted_user_id' => (string) $userId,
                    'email' => $email,
                    'role' => (string) $invitation['role'],
                    'auth_provider' => 'password',
                ]
            );

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
                strtolower((string) $googleIdentity['email']),
                (string) $invitation['invitee_name']
            );

        $this->pdo->beginTransaction();
        try {
            $insertUser = $this->pdo->prepare(
                'INSERT INTO users (email, display_name, auth_provider, google_sub, avatar_url, email_verified, role, financial_privacy_state) VALUES (:email, :display_name, :auth_provider, :google_sub, :avatar_url, 1, :role, \'vault_setup_required\')'
            );
            $insertUser->execute([
                ':email' => strtolower($googleIdentity['email']),
                ':display_name' => $resolvedDisplayName,
                ':auth_provider' => 'google',
                ':google_sub' => $googleIdentity['google_sub'],
                ':avatar_url' => $googleAvatarUrl,
                ':role' => (string) $invitation['role'],
            ]);

            $userId = (int) $this->pdo->lastInsertId();
            $this->markInvitationAccepted((int) $invitation['id'], $userId);
            $this->audit->record(
                $request,
                $userId,
                'session',
                'invitation.accepted',
                'invitation',
                (string) $invitation['invite_id'],
                [
                    'accepted_user_id' => (string) $userId,
                    'email' => strtolower((string) $googleIdentity['email']),
                    'role' => (string) $invitation['role'],
                    'auth_provider' => 'google',
                ]
            );

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
            $this->audit->record(
                $request,
                (int) $row['id'],
                'session',
                'invitation.accepted',
                'invitation',
                (string) $invitation['invite_id'],
                [
                    'accepted_user_id' => (string) $row['id'],
                    'email' => strtolower((string) $googleIdentity['email']),
                    'role' => (string) $invitation['role'],
                    'auth_provider' => 'google',
                    'existing_user' => true,
                ]
            );
            $session = $this->createSession((int) $row['id'], $clientType, $request);
            return $this->buildAuthResponse((int) $row['id'], $session, $clientType);
        }

        $this->pdo->beginTransaction();
        try {
            $insertUser = $this->pdo->prepare(
                'INSERT INTO users (email, display_name, auth_provider, google_sub, avatar_url, email_verified, role, financial_privacy_state) VALUES (:email, :display_name, :auth_provider, :google_sub, :avatar_url, 1, :role, \'vault_setup_required\')'
            );
            $insertUser->execute([
                ':email' => strtolower((string) $googleIdentity['email']),
                ':display_name' => (string) $invitation['invitee_name'],
                ':auth_provider' => 'google',
                ':google_sub' => (string) $googleIdentity['google_sub'],
                ':avatar_url' => $googleAvatarUrl,
                ':role' => (string) $invitation['role'],
            ]);

            $userId = (int) $this->pdo->lastInsertId();
            $this->markInvitationAccepted((int) $invitation['id'], $userId);
            $this->audit->record(
                $request,
                $userId,
                'session',
                'invitation.accepted',
                'invitation',
                (string) $invitation['invite_id'],
                [
                    'accepted_user_id' => (string) $userId,
                    'email' => strtolower((string) $googleIdentity['email']),
                    'role' => (string) $invitation['role'],
                    'auth_provider' => 'google',
                ]
            );
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

    public function requestPasswordReset(Request $request): Response
    {
        $payload = $request->json();
        $email = strtolower(trim((string) ($payload['email'] ?? '')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'email', 'message' => 'must be a valid email'],
            ]);
        }

        $genericResponse = Response::json([
            'status' => 'accepted',
            'message' => 'If a password account exists for that email, a reset link has been sent.',
        ], 202);

        $lookup = $this->pdo->prepare(
            'SELECT id, email, display_name FROM users WHERE email = :email AND auth_provider = :auth_provider AND is_active = 1 LIMIT 1'
        );
        $lookup->execute([
            ':email' => $email,
            ':auth_provider' => 'password',
        ]);
        $user = $lookup->fetch();

        if (!$user) {
            return $genericResponse;
        }

        $requestId = Str::randomId('prr');
        $resetToken = Str::randomHex(24);
        $resetTokenHash = Str::hashSha256($resetToken);
        $ttlMinutes = max(1, $this->config->getInt('PASSWORD_RESET_TOKEN_TTL_MINUTES', 30));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($ttlMinutes * 60));

        $this->pdo->beginTransaction();
        try {
            $cancelPending = $this->pdo->prepare(
                "UPDATE password_reset_requests SET status = 'cancelled' WHERE user_id = :user_id AND status = 'pending'"
            );
            $cancelPending->execute([':user_id' => $user['id']]);

            $insert = $this->pdo->prepare(
                "INSERT INTO password_reset_requests (request_id, user_id, reset_token_hash, status, expires_at) VALUES (:request_id, :user_id, :reset_token_hash, 'pending', :expires_at)"
            );
            $insert->execute([
                ':request_id' => $requestId,
                ':user_id' => $user['id'],
                ':reset_token_hash' => $resetTokenHash,
                ':expires_at' => $expiresAt,
            ]);

            $this->sendPasswordResetEmail(
                toEmail: (string) $user['email'],
                resetToken: $resetToken,
                expiresAt: $expiresAt,
                displayName: (string) $user['display_name']
            );

            $this->audit->record(
                $request,
                null,
                'system',
                'profile.password_reset_requested',
                'user',
                (string) $user['id'],
                [
                    'email' => (string) $user['email'],
                    'reset_request_id' => $requestId,
                    'expires_at' => $expiresAt,
                ]
            );

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $genericResponse;
    }

    public function confirmPasswordReset(Request $request): Response
    {
        $payload = $request->json();
        $resetToken = trim((string) ($payload['reset_token'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if ($resetToken === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'reset_token', 'message' => 'is required'],
            ]);
        }

        $this->validatePassword($password);
        $resetTokenHash = Str::hashSha256($resetToken);

        $stmt = $this->pdo->prepare(
            "SELECT
                prr.id,
                prr.request_id,
                prr.user_id,
                u.email
             FROM password_reset_requests prr
             JOIN users u ON u.id = prr.user_id
             WHERE prr.reset_token_hash = :reset_token_hash
               AND prr.status = 'pending'
               AND prr.expires_at > UTC_TIMESTAMP()
               AND u.auth_provider = 'password'
               AND u.is_active = 1
             LIMIT 1"
        );
        $stmt->execute([':reset_token_hash' => $resetTokenHash]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Password reset link is invalid or expired');
        }

        $this->pdo->beginTransaction();
        try {
            $updatePassword = $this->pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
            $updatePassword->execute([
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':id' => $row['user_id'],
            ]);

            $markUsed = $this->pdo->prepare("UPDATE password_reset_requests SET status = 'used', used_at = UTC_TIMESTAMP() WHERE id = :id");
            $markUsed->execute([':id' => $row['id']]);

            $revokeSessions = $this->pdo->prepare('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP() WHERE user_id = :user_id AND revoked_at IS NULL');
            $revokeSessions->execute([':user_id' => $row['user_id']]);

            $this->audit->record(
                $request,
                (int) $row['user_id'],
                'system',
                'profile.password_reset_completed',
                'user',
                (string) $row['user_id'],
                [
                    'email' => (string) $row['email'],
                    'reset_request_id' => (string) $row['request_id'],
                    'sessions_revoked' => $revokeSessions->rowCount(),
                ]
            );

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return Response::json([
            'status' => 'completed',
            'message' => 'Password has been reset. Sign in with your new password.',
        ]);
    }

    /** @return array<string,mixed> */
    private function getActiveInvitationByToken(string $inviteToken): array
    {
        $hash = Str::hashSha256($inviteToken);

        $stmt = $this->pdo->prepare(
            "SELECT id, invite_id, invitee_name, email, role FROM invitations WHERE invite_token_hash = :token_hash AND status = 'pending' AND expires_at > UTC_TIMESTAMP() LIMIT 1"
        );
        $stmt->execute([':token_hash' => $hash]);
        $invitation = $stmt->fetch();

        if (!$invitation) {
            throw new HttpException(404, 'NOT_FOUND', 'Invitation not found or expired');
        }

        return $invitation;
    }

    /** @param array<string,mixed> $row */
    private function invitationStatus(array $row): string
    {
        $status = (string) ($row['status'] ?? 'pending');
        if ($status === 'pending' && strtotime((string) ($row['expires_at'] ?? '') . ' UTC') <= time()) {
            return 'expired';
        }

        return $status;
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

        $hasDeviceId = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' || array_filter($this->pdo->query('PRAGMA table_info(user_sessions)')->fetchAll(), static fn (array $column): bool => (string) ($column['name'] ?? '') === 'device_id') !== [];
        $stmt = $this->pdo->prepare($hasDeviceId
            ? 'INSERT INTO user_sessions (session_id, user_id, device_id, session_secret_hash, csrf_token_hash, client_type, ip_address, user_agent, last_seen_at, expires_at) VALUES (:session_id, :user_id, :device_id, :session_secret_hash, :csrf_token_hash, :client_type, :ip_address, :user_agent, UTC_TIMESTAMP(), :expires_at)'
            : 'INSERT INTO user_sessions (session_id, user_id, session_secret_hash, csrf_token_hash, client_type, ip_address, user_agent, last_seen_at, expires_at) VALUES (:session_id, :user_id, :session_secret_hash, :csrf_token_hash, :client_type, :ip_address, :user_agent, UTC_TIMESTAMP(), :expires_at)'
        );

        $parameters = [
            ':session_id' => $sessionId,
            ':user_id' => $userId,
            ':session_secret_hash' => $sessionSecretHash,
            ':csrf_token_hash' => $csrfTokenHash,
            ':client_type' => $clientType,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent' => substr((string) ($request->header('User-Agent') ?? ''), 0, 255),
            ':expires_at' => $expiresAt,
        ];
        if ($hasDeviceId) $parameters[':device_id'] = $this->deviceId($request);
        $stmt->execute($parameters);

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

    private function deviceId(Request $request): string
    {
        $value = trim((string) ($request->header('X-Budget-Device-ID') ?? ''));
        if (preg_match('/^dev_[A-Za-z0-9_-]{10,64}$/', $value) === 1) return $value;
        return Str::randomId('dev');
    }

    private function buildAuthResponse(int $userId, array $session, string $clientType, int $statusCode = 200): Response
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, display_name, auth_provider, role, avatar_url, user_preferences FROM users WHERE id = :id LIMIT 1'
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
                'role' => (string) $user['role'],
                'avatar_url' => $user['avatar_url'] !== null ? (string) $user['avatar_url'] : null,
                'onboarding_complete' => $this->isOnboardingComplete($userId, (string) $user['display_name']),
                'user_preferences' => $this->normalizePreferences($user['user_preferences'] ?? null),
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

    /** @return array<string,mixed> */
    private function normalizePreferences(mixed $raw): array
    {
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $this->validatedPreferences($decoded);
            }
        }

        if (is_array($raw)) {
            return $this->validatedPreferences($raw);
        }

        return $this->defaultPreferences();
    }

    /** @param array<string,mixed> $preferences
     *  @return array<string,mixed>
     */
    private function validatedPreferences(array $preferences): array
    {
        $appearance = is_array($preferences['appearance'] ?? null) ? $preferences['appearance'] : [];
        $theme = (string) ($appearance['theme'] ?? 'system');
        if (!in_array($theme, ['light', 'dark', 'system'], true)) {
            $theme = 'system';
        }

        $onboarding = is_array($preferences['onboarding'] ?? null) ? $preferences['onboarding'] : [];
        $dismissed = is_bool($onboarding['dismissed'] ?? null) ? $onboarding['dismissed'] : false;

        return [
            'appearance' => [
                'theme' => $theme,
            ],
            'onboarding' => [
                'dismissed' => $dismissed,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function defaultPreferences(): array
    {
        return [
            'appearance' => [
                'theme' => 'system',
            ],
            'onboarding' => [
                'dismissed' => false,
            ],
        ];
    }

    private function sendInviteEmail(
        string $toEmail,
        string $inviteToken,
        string $expiresAt,
        string $inviteeName,
        string $subject,
        string $body
    ): void
    {
        $appUrl = rtrim((string) $this->config->get('APP_URL', 'http://localhost:8000'), '/');
        $inviteUrl = $appUrl . '/invite/' . rawurlencode($inviteToken);
        $formattedExpiresAt = InviteEmailTemplate::formatPacificExpiry($expiresAt);

        $text = implode(PHP_EOL, [
            $body,
            '',
            'Accept invitation: ' . $inviteUrl,
            'Expires ' . $formattedExpiresAt,
            '',
            'If this was not expected, you can ignore this email.',
        ]);

        $html = InviteEmailTemplate::render($inviteUrl, $expiresAt, $inviteeName, $body);

        $this->mailer->send($toEmail, $subject, $text, $html);
    }

    private function preferredInviteAuthProvider(string $email): string
    {
        $normalizedEmail = strtolower(trim($email));
        $domain = substr(strrchr($normalizedEmail, '@') ?: '', 1);

        // Keep invite branching deterministic: Gmail addresses default to Google, everything else defaults to password setup.
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            return 'google';
        }

        return 'password';
    }

    private function sendPasswordResetEmail(string $toEmail, string $resetToken, string $expiresAt, string $displayName): void
    {
        $appUrl = rtrim((string) $this->config->get('APP_URL', 'http://localhost:8000'), '/');
        $resetUrl = $appUrl . '/password-reset/' . rawurlencode($resetToken);
        $expiresLabel = InviteEmailTemplate::formatPacificExpiry($expiresAt);
        $name = trim($displayName) !== '' ? trim($displayName) : 'there';

        $subject = 'Reset your Budget password';
        $text = implode(PHP_EOL, [
            'Hi ' . $name . ',',
            '',
            'Use this link to reset your Budget password:',
            $resetUrl,
            '',
            'This link expires ' . $expiresLabel . '.',
            '',
            'If you did not request this, you can ignore this email.',
        ]);

        $safeResetUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeExpires = htmlspecialchars($expiresLabel, ENT_QUOTES, 'UTF-8');
        $html = <<<HTML
<!doctype html>
<html>
  <body style="font-family: Arial, sans-serif; color: #111827;">
    <p>Hi {$safeName},</p>
    <p>Use this link to reset your Budget password:</p>
    <p><a href="{$safeResetUrl}">Reset password</a></p>
    <p>This link expires {$safeExpires}.</p>
    <p>If you did not request this, you can ignore this email.</p>
  </body>
</html>
HTML;

        $this->mailer->send($toEmail, $subject, $text, $html);
    }

    private function parseInviteExpiry(string $expiresAtInput): string
    {
        if ($expiresAtInput === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'expires_at', 'message' => 'is required'],
            ]);
        }

        try {
            $expiresAt = new \DateTimeImmutable($expiresAtInput);
        } catch (\Exception) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'expires_at', 'message' => 'must be a valid date-time'],
            ]);
        }

        $expiresAt = $expiresAt->setTimezone(new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $max = $now->modify('+30 days');

        if ($expiresAt <= $now) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'expires_at', 'message' => 'must be in the future'],
            ]);
        }

        if ($expiresAt > $max) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'expires_at', 'message' => 'must be within 30 days'],
            ]);
        }

        return $expiresAt->format('Y-m-d H:i:s');
    }

    private function validatePassword(string $password): void
    {
        if (strlen($password) < 8) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'password', 'message' => 'must be at least 8 characters'],
            ]);
        }
    }

    private function resolveGoogleDisplayName(?string $googleName, string $email, string $inviteeName = ''): string
    {
        $candidate = trim((string) ($googleName ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }

        $candidate = trim($inviteeName);
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
