<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Auth\GoogleTokenVerifier;
use App\Auth\AuthIdentityRepository;
use App\Auth\PasswordCredentialRepository;
use App\Auth\AuthMethodService;
use App\Core\Config;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Mail\Mailer;
use App\Security\AuditLogger;
use App\Support\Str;
use App\Privacy\RecentAuthGuard;
use PDO;

final class ProfileController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthService $auth,
        private readonly GoogleTokenVerifier $googleTokens,
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly AuditLogger $audit,
        private readonly AuthIdentityRepository $identities,
        private readonly PasswordCredentialRepository $passwords,
        private readonly AuthMethodService $methods,
        private readonly RecentAuthGuard $recentAuth
    ) {
    }

    public function getMe(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request);
        return Response::json($this->profileFromAuth($ctx->user));
    }

    public function getAuthMethods(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request);
        return Response::json(['methods' => array_map(static fn(\App\Auth\AuthMethod $method): array => $method->toApi(), $this->methods->listForUser($ctx->userId()))]);
    }

    public function updateMe(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request);
        $payload = $request->json();

        $displayName = trim((string) ($payload['display_name'] ?? ''));
        if ($displayName === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'display_name', 'message' => 'is required'],
            ]);
        }

        $previousDisplayName = (string) $ctx->user['display_name'];
        if ($displayName !== $previousDisplayName) {
            $stmt = $this->pdo->prepare('UPDATE users SET display_name = :display_name WHERE id = :id');
            $stmt->execute([
                ':display_name' => $displayName,
                ':id' => $ctx->userId(),
            ]);

            $this->audit->record(
                $request,
                $ctx->userId(),
                $ctx->authType,
                'profile.updated',
                'user',
                (string) $ctx->userId(),
                [
                    'fields' => ['display_name'],
                    'display_name_previous' => $previousDisplayName,
                    'display_name_next' => $displayName,
                ]
            );
        }

        $profile = $this->fetchProfile($ctx->userId());
        return Response::json($profile);
    }

    public function getSetupStatus(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request);

        return Response::json($this->buildSetupStatus($ctx->userId()));
    }

    public function updateOnboardingState(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request);
        $payload = $request->json();

        if (!is_array($payload) || !array_key_exists('onboarding_dismissed', $payload) || !is_bool($payload['onboarding_dismissed'])) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'onboarding_dismissed', 'message' => 'must be a boolean'],
            ]);
        }

        $current = $this->fetchPreferences($ctx->userId());
        $next = $current;
        $next['onboarding']['dismissed'] = $payload['onboarding_dismissed'];
        $this->savePreferences($ctx->userId(), $this->validatedPreferences($next));

        return Response::json([
            'onboarding_dismissed' => (bool) $payload['onboarding_dismissed'],
        ]);
    }

    public function updatePreferences(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request);
        $payload = $request->json();
        if (!is_array($payload)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed');
        }

        $current = $this->fetchPreferences($ctx->userId());
        $next = $this->mergePreferences($current, $payload);
        $this->savePreferences($ctx->userId(), $next);

        if ($next !== $current) {
            $this->audit->record(
                $request,
                $ctx->userId(),
                $ctx->authType,
                'profile.preferences_updated',
                'user',
                (string) $ctx->userId(),
                [
                    'previous' => $current,
                    'next' => $next,
                ]
            );
        }

        return Response::json($next);
    }

    /** @param array<string,mixed> $preferences */
    private function savePreferences(int $userId, array $preferences): void
    {
        $encoded = json_encode($preferences, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new HttpException(500, 'INTERNAL_ERROR', 'Unable to encode user preferences');
        }

        $stmt = $this->pdo->prepare('UPDATE users SET user_preferences = :user_preferences WHERE id = :id');
        $stmt->execute([
            ':user_preferences' => $encoded,
            ':id' => $userId,
        ]);
    }

    public function requestEmailChange(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request);
        if (!$this->hasPasswordCredential($ctx->userId())) {
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

            $this->audit->record(
                $request,
                $ctx->userId(),
                $ctx->authType,
                'profile.email_change_requested',
                'email_change_request',
                $requestId,
                [
                    'current_email' => (string) $ctx->user['email'],
                    'new_email' => $newEmail,
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

        return Response::json([
            'email_change_id' => $requestId,
            'status' => 'verification_pending',
        ], 202);
    }

    public function verifyEmailChange(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request);
        if (!$this->hasPasswordCredential($ctx->userId())) {
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

            $this->audit->record(
                $request,
                $ctx->userId(),
                $ctx->authType,
                'profile.email_changed',
                'user',
                (string) $ctx->userId(),
                [
                    'previous_email' => (string) $ctx->user['email'],
                    'new_email' => (string) $row['new_email'],
                    'email_change_id' => $requestId,
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
            'email' => (string) $row['new_email'],
            'email_verified' => true,
        ]);
    }

    public function connectGoogle(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request);
        $this->recentAuth->requireRecentInteractiveSession($ctx);

        $payload = $request->json();
        $googleIdToken = trim((string) ($payload['google_id_token'] ?? ''));
        if ($googleIdToken === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'google_id_token', 'message' => 'is required'],
            ]);
        }

        $googleIdentity = $this->googleTokens->verifyIdToken($googleIdToken);
        $googleEmail = strtolower((string) $googleIdentity['email']);
        $this->methods->connectGoogle($ctx->userId(), (string) $googleIdentity['subject'], $googleEmail, true);
        return $this->completeMethodMutation($request, $ctx, 'auth_method.connected', ['provider' => 'google']);
    }

    public function addPassword(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request); $this->recentAuth->requireRecentInteractiveSession($ctx);
        if (!(bool) $ctx->user['email_verified']) throw new HttpException(403, 'FORBIDDEN', 'A verified account email is required before adding password sign-in.');
        $password = (string) ($request->json()['password'] ?? ''); $this->validatePassword($password);
        $this->methods->addPassword($ctx->userId(), password_hash($password, PASSWORD_DEFAULT));
        return $this->completeMethodMutation($request, $ctx, 'auth_method.connected', ['method' => 'password']);
    }

    public function changePassword(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request); $this->recentAuth->requireRecentInteractiveSession($ctx);
        $password = (string) ($request->json()['password'] ?? ''); $this->validatePassword($password);
        $this->methods->changePassword($ctx->userId(), password_hash($password, PASSWORD_DEFAULT));
        return $this->completeMethodMutation($request, $ctx, 'auth_method.password_changed', ['method' => 'password']);
    }

    public function removePassword(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request); $this->recentAuth->requireRecentInteractiveSession($ctx);
        $this->methods->removePassword($ctx->userId());
        return $this->completeMethodMutation($request, $ctx, 'auth_method.disconnected', ['method' => 'password']);
    }

    public function removeGoogle(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request); $this->recentAuth->requireRecentInteractiveSession($ctx);
        $this->methods->removeExternalProvider($ctx->userId(), 'google');
        return $this->completeMethodMutation($request, $ctx, 'auth_method.disconnected', ['provider' => 'google']);
    }

    /** @param array<string,string> $metadata */
    private function completeMethodMutation(Request $request, \App\Auth\AuthContext $ctx, string $event, array $metadata): Response
    {
        if ($ctx->sessionId !== null) {
            $revoke = $this->pdo->prepare('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP() WHERE user_id = :user_id AND session_id <> :session_id AND revoked_at IS NULL');
            $revoke->execute([':user_id' => $ctx->userId(), ':session_id' => $ctx->sessionId]);
        }
        $this->audit->record(
            $request,
            $ctx->userId(),
            $ctx->authType,
            $event,
            'user',
            (string) $ctx->userId(),
            $metadata
        );
        return Response::json(['methods' => array_map(static fn(\App\Auth\AuthMethod $method): array => $method->toApi(), $this->methods->listForUser($ctx->userId()))]);
    }

    private function validatePassword(string $password): void
    {
        if (strlen($password) < 8) throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [['field' => 'password', 'message' => 'must be at least 8 characters']]);
    }

    private function hasPasswordCredential(int $userId): bool { return $this->methods->hasPassword($userId); }

    /** @param array<string,mixed> $user */
    private function profileFromAuth(array $user): array
    {
        $userId = (int) $user['id'];
        $displayName = (string) $user['display_name'];

        return [
            'id' => (string) $user['id'],
            'email' => (string) $user['email'],
            'display_name' => $displayName,
            'role' => (string) $user['role'],
            'avatar_url' => $user['avatar_url'] !== null ? (string) $user['avatar_url'] : null,
            'email_verified' => (bool) $user['email_verified'],
            'created_at' => (string) $user['created_at'],
            'onboarding_complete' => $this->isOnboardingComplete($userId, $displayName),
            'user_preferences' => $this->normalizePreferences($user['user_preferences'] ?? null),
        ];
    }

    /** @return array<string,mixed> */
    private function fetchProfile(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, display_name, role, avatar_url, email_verified, created_at, user_preferences FROM users WHERE id = :id LIMIT 1'
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
            'role' => (string) $row['role'],
            'avatar_url' => $row['avatar_url'] !== null ? (string) $row['avatar_url'] : null,
            'email_verified' => (bool) $row['email_verified'],
            'created_at' => (string) $row['created_at'],
            'onboarding_complete' => $this->isOnboardingComplete((int) $row['id'], (string) $row['display_name']),
            'user_preferences' => $this->normalizePreferences($row['user_preferences'] ?? null),
        ];
    }

    /** @return array<string,mixed> */
    private function fetchPreferences(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT user_preferences FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new HttpException(404, 'NOT_FOUND', 'User not found');
        }

        return $this->normalizePreferences($row['user_preferences'] ?? null);
    }

    private function isOnboardingComplete(int $userId, string $displayName): bool
    {
        if (trim($displayName) === '') {
            return false;
        }

        // Financial completion is encrypted-domain data and cannot be derived
        // from the account/profile endpoint.
        return true;
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

    /** @param array<string,mixed> $current
     *  @param array<string,mixed> $patch
     *  @return array<string,mixed>
     */
    private function mergePreferences(array $current, array $patch): array
    {
        $next = $current;

        if (array_key_exists('appearance', $patch)) {
            if (!is_array($patch['appearance'])) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'appearance', 'message' => 'must be an object'],
                ]);
            }

            $nextAppearance = is_array($next['appearance'] ?? null) ? $next['appearance'] : [];
            if (array_key_exists('theme', $patch['appearance'])) {
                $theme = (string) $patch['appearance']['theme'];
                if (!in_array($theme, ['light', 'dark', 'system'], true)) {
                    throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                        ['field' => 'appearance.theme', 'message' => 'must be light, dark, or system'],
                    ]);
                }
                $nextAppearance['theme'] = $theme;
            }
            $next['appearance'] = $nextAppearance;
        }

        if (array_key_exists('onboarding', $patch)) {
            if (!is_array($patch['onboarding'])) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'onboarding', 'message' => 'must be an object'],
                ]);
            }

            $nextOnboarding = is_array($next['onboarding'] ?? null) ? $next['onboarding'] : [];
            if (array_key_exists('dismissed', $patch['onboarding'])) {
                if (!is_bool($patch['onboarding']['dismissed'])) {
                    throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                        ['field' => 'onboarding.dismissed', 'message' => 'must be a boolean'],
                    ]);
                }

                $nextOnboarding['dismissed'] = $patch['onboarding']['dismissed'];
            }
            $next['onboarding'] = $nextOnboarding;
        }

        $unsupported = array_diff(array_keys($patch), ['appearance', 'onboarding']);
        if ($unsupported !== []) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => (string) array_values($unsupported)[0], 'message' => 'is not supported'],
            ]);
        }

        return $this->validatedPreferences($next);
    }

    /** @param array<string,mixed> $preferences
     *  @return array<string,mixed>
     */
    private function validatedPreferences(array $preferences): array
    {
        $defaults = $this->defaultPreferences();
        $appearance = is_array($preferences['appearance'] ?? null) ? $preferences['appearance'] : [];
        $theme = (string) ($appearance['theme'] ?? $defaults['appearance']['theme']);
        $onboarding = is_array($preferences['onboarding'] ?? null) ? $preferences['onboarding'] : [];
        $dismissed = is_bool($onboarding['dismissed'] ?? null)
            ? $onboarding['dismissed']
            : $defaults['onboarding']['dismissed'];

        if (!in_array($theme, ['light', 'dark', 'system'], true)) {
            $theme = $defaults['appearance']['theme'];
        }

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

    /** @return array<string,mixed> */
    private function buildSetupStatus(int $userId): array
    {
        $preferences = $this->fetchPreferences($userId);
        $onboardingDismissed = (bool) ($preferences['onboarding']['dismissed'] ?? false);

        return [
            // These fields remain for account UI compatibility, but the
            // financial facts are owned by the encrypted client domain.
            'budget_profile_complete' => true,
            'has_transactions' => true,
            'has_recurring_expenses' => true,
            'has_imported_data' => true,
            'first_transaction_added' => true,
            'first_recurring_expense_added' => true,
            'first_import_completed' => true,
            'onboarding_dismissed' => $onboardingDismissed,
            'recommended_next_action' => 'none',
            'setup_tasks' => [
                $this->setupTask('add_first_transaction', 'Add your first transaction', true),
                $this->setupTask('add_recurring_expenses', 'Add fixed monthly bills', true),
                $this->setupTask('import_transactions', 'Import past transactions', true),
            ],
        ];
    }

    /** @return array{key:string,label:string,status:string,completed:bool} */
    private function setupTask(string $key, string $label, bool $completed): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $completed ? 'completed' : 'available',
            'completed' => $completed,
        ];
    }

    private function fmt(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
