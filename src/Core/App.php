<?php

declare(strict_types=1);

namespace App\Core;

use App\Auth\AuthService;
use App\Auth\AuthApplicationService;
use App\Auth\AccountAuthenticationService;
use App\Auth\InvitationService;
use App\Auth\SessionService;
use App\Auth\PasswordResetService;
use App\Auth\GoogleTokenVerifier;
use App\Controllers\AuditLogController;
use App\Controllers\InvitationController;
use App\Controllers\SessionController;
use App\Controllers\PasswordResetController;
use App\Controllers\HealthController;
use App\Controllers\ProfileController;
use App\Controllers\PrivacyStatusController;
use App\Controllers\VaultController;
use App\Controllers\QuickUnlockController;
use App\Controllers\EncryptedRecordController;
use App\Database\Connection;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Mail\Mailer;
use App\Monitoring\ErrorReporter;
use App\Monitoring\StructuredLogger;
use App\Security\AuditLogger;
use App\Security\RateLimiter;
use App\Privacy\FinancialPrivacyStateService;
use App\Privacy\VaultRepository;
use App\Privacy\VaultService;
use App\Privacy\QuickUnlockRepository;
use App\Privacy\QuickUnlockService;
use App\Devices\DeviceLifecycleService;
use App\Privacy\EncryptedRecordRepository;
use App\Privacy\EncryptedRecordService;
use Throwable;

final class App
{
    private function __construct(
        private readonly Router $router,
        private readonly Config $config,
        private readonly RateLimiter $rateLimiter,
        private readonly ErrorReporter $errorReporter
    ) {
    }

    public static function create(): self
    {
        $root = dirname(__DIR__, 2);
        $config = Config::load($root);
        $pdo = Connection::make($config);

        $auth = new AuthService($pdo, $config);
        $mailer = new Mailer($config);
        $rateLimiter = new RateLimiter($config);
        $structuredLogger = new StructuredLogger($config);
        $errorReporter = new ErrorReporter($config, $structuredLogger);
        $auditLogger = new AuditLogger($pdo);
        $googleTokenVerifier = new GoogleTokenVerifier($config, $structuredLogger);
        $authApplication = new AuthApplicationService($pdo, $auth, $googleTokenVerifier, $mailer, $config, $auditLogger);
        $invitationService = new InvitationService($authApplication);
        $accountAuthenticationService = new AccountAuthenticationService($authApplication);
        $sessionService = new SessionService($authApplication);
        $passwordResetService = new PasswordResetService($authApplication);
        $invitationController = new InvitationController($invitationService, $accountAuthenticationService);
        $sessionController = new SessionController($accountAuthenticationService, $sessionService);
        $passwordResetController = new PasswordResetController($passwordResetService);
        $auditLogController = new AuditLogController($pdo, $auth);
        $profileController = new ProfileController($pdo, $auth, $googleTokenVerifier, $mailer, $config, $auditLogger);
        $healthController = new HealthController($structuredLogger);
        $financialStates = new FinancialPrivacyStateService($pdo);
        $vaultRepository = new VaultRepository($pdo);
        $privacyStatusController = new PrivacyStatusController($auth, $financialStates);
        $recentAuth = new \App\Privacy\RecentAuthGuard($config);
        $vaultController = new VaultController($auth, new VaultService($pdo, $vaultRepository, $financialStates, $recentAuth, $auditLogger));
        $quickUnlockController = new QuickUnlockController($auth, new QuickUnlockService($pdo, $config, $financialStates, $vaultRepository, $recentAuth, new QuickUnlockRepository($pdo), $auditLogger));
        $deviceLifecycle = new DeviceLifecycleService($pdo, new QuickUnlockRepository($pdo), $auditLogger);
        $deviceController = new \App\Controllers\DeviceController($pdo, $auth, $recentAuth, $deviceLifecycle);
        $encryptedRecordController = new EncryptedRecordController($auth, new EncryptedRecordService($pdo, new EncryptedRecordRepository($pdo), new VaultRepository($pdo), $financialStates, $auditLogger));

        $router = new Router();

        $add = static function (string $method, string $path, callable $handler) use ($router): void {
            $router->add($method, '/api/v1' . $path, $handler);
            $router->add($method, $path, $handler);
        };

        $add('GET', '/health', fn(Request $request) => $healthController($request));

        $add('GET', '/auth/invitations', fn(Request $request) => $invitationController->list($request));
        $add('POST', '/auth/invitations', fn(Request $request) => $invitationController->create($request));
        $add('DELETE', '/auth/invitations/{invite_id}', fn(Request $request, array $params) => $invitationController->revoke($request, $params));
        $add('DELETE', '/auth/invitations/{invite_id}/account', fn(Request $request, array $params) => $invitationController->deleteAccount($request, $params));
        $add('GET', '/auth/invitations/preview', fn(Request $request) => $invitationController->preview($request));
        $add('POST', '/auth/invitations/accept-password', fn(Request $request) => $invitationController->acceptPassword($request));
        $add('POST', '/auth/invitations/accept-google', fn(Request $request) => $invitationController->acceptGoogle($request));
        $add('POST', '/auth/sessions/password', fn(Request $request) => $sessionController->passwordSignIn($request));
        $add('POST', '/auth/sessions/google', fn(Request $request) => $sessionController->googleSignIn($request));
        $add('POST', '/auth/sessions/reauth', fn(Request $request) => $sessionController->reauthenticate($request));
        $add('GET', '/auth/sessions/current', fn(Request $request) => $sessionController->refreshCsrf($request));
        $add('DELETE', '/auth/sessions/current', fn(Request $request) => $sessionController->signOut($request));
        $add('POST', '/auth/password-reset/request', fn(Request $request) => $passwordResetController->request($request));
        $add('POST', '/auth/password-reset/confirm', fn(Request $request) => $passwordResetController->confirm($request));

        $add('GET', '/me', fn(Request $request) => $profileController->getMe($request));
        $add('GET', '/me/privacy', $privacyStatusController);
        $add('GET', '/me/vault', fn(Request $request) => $vaultController->get($request));
        $add('POST', '/me/vault', fn(Request $request) => $vaultController->initialize($request));
        $add('PUT', '/me/vault/passphrase', fn(Request $request) => $vaultController->replacePassphrase($request));
        $add('PUT', '/me/vault/recovery', fn(Request $request) => $vaultController->replaceRecovery($request));
        $add('POST', '/me/vault/quick-unlock/registration/options', fn(Request $request) => $quickUnlockController->registrationOptions($request));
        $add('POST', '/me/vault/quick-unlock/registration/complete', fn(Request $request) => $quickUnlockController->registrationComplete($request));
        $add('POST', '/me/vault/quick-unlock/assertion/options', fn(Request $request) => $quickUnlockController->assertionOptions($request));
        $add('GET', '/me/vault/quick-unlock', fn(Request $request) => $quickUnlockController->status($request));
        $add('POST', '/me/vault/quick-unlock/assertion/complete', fn(Request $request) => $quickUnlockController->assertionComplete($request));
        $add('DELETE', '/me/vault/quick-unlock/{quick_unlock_id}', fn(Request $request, array $params) => $quickUnlockController->revoke($request, $params));
        $add('GET', '/me/devices', fn(Request $request) => $deviceController->list($request));
        $add('DELETE', '/me/devices/{device_id}', fn(Request $request, array $params) => $deviceController->revoke($request, $params));
        $add('POST', '/me/encrypted-records', fn(Request $request) => $encryptedRecordController->create($request));
        $add('POST', '/me/encrypted-records/batch', fn(Request $request) => $encryptedRecordController->batch($request));
        $add('GET', '/me/encrypted-records/sync', fn(Request $request) => $encryptedRecordController->sync($request));
        $add('GET', '/me/encrypted-records/{record_id}', fn(Request $request, array $params) => $encryptedRecordController->get($request, $params));
        $add('PUT', '/me/encrypted-records/{record_id}', fn(Request $request, array $params) => $encryptedRecordController->update($request, $params));
        $add('DELETE', '/me/encrypted-records/{record_id}', fn(Request $request, array $params) => $encryptedRecordController->delete($request, $params));
        $add('PATCH', '/me', fn(Request $request) => $profileController->updateMe($request));
        $add('GET', '/me/setup-status', fn(Request $request) => $profileController->getSetupStatus($request));
        $add('PATCH', '/me/onboarding-state', fn(Request $request) => $profileController->updateOnboardingState($request));
        $add('PATCH', '/me/preferences', fn(Request $request) => $profileController->updatePreferences($request));
        $add('POST', '/me/email-change/request', fn(Request $request) => $profileController->requestEmailChange($request));
        $add('POST', '/me/email-change/verify', fn(Request $request) => $profileController->verifyEmailChange($request));
        $add('POST', '/me/auth/convert-google', fn(Request $request) => $profileController->convertAccountToGoogle($request));

        $add('GET', '/me/audit-logs', fn(Request $request) => $auditLogController->list($request));

        return new self($router, $config, $rateLimiter, $errorReporter);
    }

    public function handle(Request $request): Response
    {
        $requestId = $this->requestId($request);

        try {
            $this->enforceRateLimits($request);
            $response = $this->router->dispatch($request);
        } catch (HttpException $e) {
            if ($e->status >= 500) {
                $this->errorReporter->reportException($request, $e, $e->status, $requestId);
            }
            $response = Response::json([
                'error' => [
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                    'details' => $e->details(),
                ],
            ], $e->status);
        } catch (Throwable $e) {
            $body = [
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'Internal server error',
                    'details' => [],
                ],
            ];

            if ($this->debugModeEnabled()) {
                $body['error']['debug'] = [
                    'type' => $e::class,
                ];
            }

            $this->errorReporter->reportException($request, $e, 500, $requestId);
            $response = Response::json($body, 500);
        }

        return $this->applySecurityHeaders($request, $response)
            ->withHeader('X-Request-ID', $requestId);
    }

    private function enforceRateLimits(Request $request): void
    {
        $method = strtoupper($request->method);
        $path = $this->normalizePath($request->path);
        $clientIdentifier = $this->clientIdentifier($request);

        if ($method === 'POST' && in_array($path, ['/auth/sessions/password', '/auth/sessions/google', '/auth/sessions/reauth'], true)) {
            $max = $this->config->getInt('RATE_LIMIT_AUTH_MAX', 10);
            $window = $this->config->getInt('RATE_LIMIT_AUTH_WINDOW_SECONDS', 60);
            $this->rateLimiter->hit('auth:' . $path . ':' . $clientIdentifier, $max, $window);
            return;
        }

        if ($method === 'POST' && in_array($path, ['/auth/invitations/accept-password', '/auth/invitations/accept-google'], true)) {
            $max = $this->config->getInt('RATE_LIMIT_INVITE_ACCEPT_MAX', 10);
            $window = $this->config->getInt('RATE_LIMIT_INVITE_ACCEPT_WINDOW_SECONDS', 60);
            $this->rateLimiter->hit('invite-accept:' . $path . ':' . $clientIdentifier, $max, $window);
            return;
        }

        if ($method === 'POST' && $path === '/auth/password-reset/request') {
            $max = $this->config->getInt('RATE_LIMIT_PASSWORD_RESET_REQUEST_MAX', 5);
            $window = $this->config->getInt('RATE_LIMIT_PASSWORD_RESET_REQUEST_WINDOW_SECONDS', 600);
            $this->rateLimiter->hit('password-reset-request:' . $clientIdentifier, $max, $window);
            return;
        }

        if ($method === 'POST' && $path === '/auth/password-reset/confirm') {
            $max = $this->config->getInt('RATE_LIMIT_PASSWORD_RESET_CONFIRM_MAX', 10);
            $window = $this->config->getInt('RATE_LIMIT_PASSWORD_RESET_CONFIRM_WINDOW_SECONDS', 600);
            $this->rateLimiter->hit('password-reset-confirm:' . $clientIdentifier, $max, $window);
            return;
        }

        if ($method === 'POST' && $path === '/auth/invitations') {
            $this->hitAuthenticatedRateLimit(
                $request,
                'invite-create',
                $this->config->getInt('RATE_LIMIT_INVITE_CREATE_MAX', 10),
                $this->config->getInt('RATE_LIMIT_INVITE_CREATE_WINDOW_SECONDS', 3600)
            );
            return;
        }

        if ($method === 'DELETE' && preg_match('#^/me/devices/[^/]+$#', $path) === 1) {
            $this->hitAuthenticatedRateLimit($request, 'device-removal', $this->config->getInt('RATE_LIMIT_DEVICE_REMOVAL_MAX', 10), $this->config->getInt('RATE_LIMIT_DEVICE_REMOVAL_WINDOW_SECONDS', 600));
            return;
        }

        if ($method === 'PATCH' && in_array($path, ['/me', '/me/preferences', '/me/onboarding-state'], true)) {
            $this->hitAuthenticatedRateLimit(
                $request,
                'profile-change',
                $this->config->getInt('RATE_LIMIT_PROFILE_CHANGE_MAX', 30),
                $this->config->getInt('RATE_LIMIT_PROFILE_CHANGE_WINDOW_SECONDS', 3600)
            );
            return;
        }

        if ($method === 'POST' && $path === '/me/email-change/request') {
            $max = $this->config->getInt('RATE_LIMIT_EMAIL_CHANGE_REQUEST_MAX', 5);
            $window = $this->config->getInt('RATE_LIMIT_EMAIL_CHANGE_REQUEST_WINDOW_SECONDS', 600);
            $this->hitAuthenticatedRateLimit($request, 'email-change-request', $max, $window);
            return;
        }

        if ($method === 'POST' && $path === '/me/email-change/verify') {
            $max = $this->config->getInt('RATE_LIMIT_EMAIL_CHANGE_VERIFY_MAX', 10);
            $window = $this->config->getInt('RATE_LIMIT_EMAIL_CHANGE_VERIFY_WINDOW_SECONDS', 600);
            $this->hitAuthenticatedRateLimit($request, 'email-change-verify', $max, $window);
            return;
        }

        if ($method === 'POST' && $path === '/me/auth/convert-google') {
            $this->hitAuthenticatedRateLimit(
                $request,
                'convert-google',
                $this->config->getInt('RATE_LIMIT_AUTH_CONVERT_MAX', 5),
                $this->config->getInt('RATE_LIMIT_AUTH_CONVERT_WINDOW_SECONDS', 600)
            );
            return;
        }

        if (($method === 'GET' && $path === '/me/vault/quick-unlock')
            || ($method === 'POST' && preg_match('#^/me/vault/quick-unlock/(registration|assertion)/(options|complete)$#', $path) === 1)
            || ($method === 'DELETE' && preg_match('#^/me/vault/quick-unlock/[^/]+$#', $path) === 1)) {
            $this->hitAuthenticatedRateLimit(
                $request,
                'quick-unlock',
                $this->config->getInt('RATE_LIMIT_QUICK_UNLOCK_MAX', 20),
                $this->config->getInt('RATE_LIMIT_QUICK_UNLOCK_WINDOW_SECONDS', 600)
            );
            return;
        }
    }

    private function hitAuthenticatedRateLimit(Request $request, string $bucket, int $max, int $windowSeconds): void
    {
        $actorIdentifier = $this->requestCredentialIdentifier($request);
        $clientIdentifier = $this->clientIdentifier($request);

        $this->rateLimiter->hit($bucket . ':actor:' . $actorIdentifier, $max, $windowSeconds);
        $this->rateLimiter->hit($bucket . ':client:' . $clientIdentifier, max($max * 5, $max), $windowSeconds);
    }

    private function requestCredentialIdentifier(Request $request): string
    {
        $authHeader = (string) ($request->header('Authorization') ?? '');
        if (str_starts_with($authHeader, 'Session ')) {
            $sessionId = $this->sessionIdFromToken(trim(substr($authHeader, 8)));
            if ($sessionId !== null) {
                return 'session:' . hash('sha256', $sessionId);
            }
        }

        $cookieToken = (string) ($request->cookies['sid'] ?? '');
        if ($cookieToken !== '') {
            $sessionId = $this->sessionIdFromToken($cookieToken);
            if ($sessionId !== null) {
                return 'session:' . hash('sha256', $sessionId);
            }
        }

        return 'client:' . $this->clientIdentifier($request);
    }

    private function sessionIdFromToken(string $token): ?string
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || trim($parts[0]) === '') {
            return null;
        }

        return trim($parts[0]);
    }

    private function normalizePath(string $path): string
    {
        if (str_starts_with($path, '/api/v1')) {
            $trimmed = substr($path, strlen('/api/v1'));
            return $trimmed !== '' ? $trimmed : '/';
        }

        return $path;
    }

    private function clientIdentifier(Request $request): string
    {
        $trustProxy = $this->config->getBool('TRUST_PROXY_HEADERS', false);
        if ($trustProxy) {
            $forwardedFor = trim((string) ($request->header('X-Forwarded-For') ?? ''));
            if ($forwardedFor !== '') {
                $firstIp = trim(explode(',', $forwardedFor)[0] ?? '');
                if ($firstIp !== '') {
                    return $firstIp;
                }
            }
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    private function debugModeEnabled(): bool
    {
        $env = strtolower(trim((string) $this->config->get('APP_ENV', 'local')));
        if ($env === 'production') {
            return false;
        }

        return $this->config->getBool('APP_DEBUG', false);
    }

    private function applySecurityHeaders(Request $request, Response $response): Response
    {
        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->withHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

        if ($this->requestIsHttps($request)) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function requestIsHttps(Request $request): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https === 'on' || $https === '1') {
            return true;
        }

        if ($this->config->getBool('TRUST_PROXY_HEADERS', false)) {
            $proto = strtolower(trim((string) ($request->header('X-Forwarded-Proto') ?? '')));
            if ($proto === 'https') {
                return true;
            }
        }

        return false;
    }

    private function requestId(Request $request): string
    {
        $incoming = trim((string) ($request->header('X-Request-ID') ?? ''));
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $incoming) === 1) {
            return $incoming;
        }

        return 'req_' . bin2hex(random_bytes(12));
    }
}
