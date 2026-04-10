<?php

declare(strict_types=1);

namespace App\Core;

use App\Auth\AuthService;
use App\Auth\GoogleTokenVerifier;
use App\Controllers\AuthController;
use App\Controllers\BudgetSettingsController;
use App\Controllers\HealthController;
use App\Controllers\ImportExportController;
use App\Controllers\MasterApiKeyController;
use App\Controllers\MetricsController;
use App\Controllers\ProfileController;
use App\Controllers\RecurringExpenseController;
use App\Controllers\TaxonomyController;
use App\Controllers\TransactionController;
use App\Database\Connection;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Mail\Mailer;
use App\Recurring\RecurringExpenseService;
use App\Security\RateLimiter;
use Throwable;

final class App
{
    private function __construct(
        private readonly Router $router,
        private readonly Config $config,
        private readonly RateLimiter $rateLimiter
    ) {
    }

    public static function create(): self
    {
        $root = dirname(__DIR__, 2);
        $config = Config::load($root);
        $pdo = Connection::make($config);

        $auth = new AuthService($pdo);
        $recurring = new RecurringExpenseService($pdo);
        $mailer = new Mailer($config);
        $rateLimiter = new RateLimiter($config);
        $googleTokenVerifier = new GoogleTokenVerifier($config);
        $authController = new AuthController($pdo, $auth, $googleTokenVerifier, $mailer, $config);
        $budgetSettingsController = new BudgetSettingsController($pdo, $auth);
        $importExportController = new ImportExportController($pdo, $auth);
        $recurringExpenseController = new RecurringExpenseController($pdo, $auth, $recurring);
        $profileController = new ProfileController($pdo, $auth, $googleTokenVerifier, $mailer, $config);
        $masterApiKeyController = new MasterApiKeyController($pdo, $auth);
        $taxonomyController = new TaxonomyController($pdo, $auth);
        $transactionController = new TransactionController($pdo, $auth, $recurring);
        $metricsController = new MetricsController($pdo, $auth, $recurring);
        $healthController = new HealthController();

        $router = new Router();

        $add = static function (string $method, string $path, callable $handler) use ($router): void {
            $router->add($method, '/api/v1' . $path, $handler);
            $router->add($method, $path, $handler);
        };

        $add('GET', '/health', fn(Request $request) => $healthController($request));

        $add('POST', '/auth/invitations', fn(Request $request) => $authController->createInvitation($request));
        $add('POST', '/auth/invitations/accept-password', fn(Request $request) => $authController->acceptInvitationPassword($request));
        $add('POST', '/auth/invitations/accept-google', fn(Request $request) => $authController->acceptInvitationGoogle($request));
        $add('POST', '/auth/sessions/password', fn(Request $request) => $authController->signInPassword($request));
        $add('POST', '/auth/sessions/google', fn(Request $request) => $authController->signInGoogle($request));
        $add('DELETE', '/auth/sessions/current', fn(Request $request) => $authController->signOutCurrentSession($request));

        $add('GET', '/me', fn(Request $request) => $profileController->getMe($request));
        $add('PATCH', '/me', fn(Request $request) => $profileController->updateMe($request));
        $add('POST', '/me/email-change/request', fn(Request $request) => $profileController->requestEmailChange($request));
        $add('POST', '/me/email-change/verify', fn(Request $request) => $profileController->verifyEmailChange($request));
        $add('POST', '/me/auth/convert-google', fn(Request $request) => $profileController->convertAccountToGoogle($request));

        $add('GET', '/me/master-api-keys', fn(Request $request) => $masterApiKeyController->listKeys($request));
        $add('POST', '/me/master-api-keys', fn(Request $request) => $masterApiKeyController->create($request));
        $add('DELETE', '/me/master-api-keys/{api_key_id}', fn(Request $request, array $params) => $masterApiKeyController->revoke($request, $params));

        $add('GET', '/me/budget-settings', fn(Request $request) => $budgetSettingsController->get($request));
        $add('PUT', '/me/budget-settings', fn(Request $request) => $budgetSettingsController->upsert($request));

        $add('GET', '/me/tags', fn(Request $request) => $taxonomyController->listTags($request));
        $add('POST', '/me/tags', fn(Request $request) => $taxonomyController->createTag($request));
        $add('PATCH', '/me/tags/{tag_id}', fn(Request $request, array $params) => $taxonomyController->updateTag($request, $params));
        $add('DELETE', '/me/tags/{tag_id}', fn(Request $request, array $params) => $taxonomyController->deleteTag($request, $params));

        $add('GET', '/me/cards', fn(Request $request) => $taxonomyController->listCards($request));
        $add('POST', '/me/cards', fn(Request $request) => $taxonomyController->createCard($request));
        $add('PATCH', '/me/cards/{card_id}', fn(Request $request, array $params) => $taxonomyController->updateCard($request, $params));
        $add('DELETE', '/me/cards/{card_id}', fn(Request $request, array $params) => $taxonomyController->deleteCard($request, $params));

        $add('GET', '/me/recurring-expenses', fn(Request $request) => $recurringExpenseController->list($request));
        $add('POST', '/me/recurring-expenses', fn(Request $request) => $recurringExpenseController->create($request));
        $add('PATCH', '/me/recurring-expenses/{recurring_expense_id}', fn(Request $request, array $params) => $recurringExpenseController->update($request, $params));
        $add('DELETE', '/me/recurring-expenses/{recurring_expense_id}', fn(Request $request, array $params) => $recurringExpenseController->delete($request, $params));

        $add('GET', '/me/transactions', fn(Request $request) => $transactionController->list($request));
        $add('POST', '/me/transactions', fn(Request $request) => $transactionController->create($request));
        $add('PATCH', '/me/transactions/{transaction_id}', fn(Request $request, array $params) => $transactionController->update($request, $params));
        $add('DELETE', '/me/transactions/{transaction_id}', fn(Request $request, array $params) => $transactionController->delete($request, $params));

        $add('GET', '/me/transactions/export.csv', fn(Request $request) => $importExportController->exportCsv($request));
        $add('POST', '/me/transactions/import.csv', fn(Request $request) => $importExportController->importCsv($request));

        $add('GET', '/me/metrics/tags', fn(Request $request) => $metricsController->tags($request));
        $add('GET', '/me/metrics/categories', fn(Request $request) => $metricsController->categories($request));
        $add('GET', '/me/metrics/insights', fn(Request $request) => $metricsController->insights($request));

        return new self($router, $config, $rateLimiter);
    }

    public function handle(Request $request): Response
    {
        try {
            $this->enforceRateLimits($request);
            $response = $this->router->dispatch($request);
        } catch (HttpException $e) {
            if ($e->status >= 500) {
                $this->logServerError($request, $e);
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
                    'message' => $e->getMessage(),
                ];
            }

            $this->logServerError($request, $e);
            $response = Response::json($body, 500);
        }

        return $this->applySecurityHeaders($request, $response);
    }

    private function enforceRateLimits(Request $request): void
    {
        if ($request->method !== 'POST') {
            return;
        }

        $path = $this->normalizePath($request->path);
        $identifier = $this->clientIdentifier($request);

        if (in_array($path, ['/auth/sessions/password', '/auth/sessions/google'], true)) {
            $max = $this->config->getInt('RATE_LIMIT_AUTH_MAX', 10);
            $window = $this->config->getInt('RATE_LIMIT_AUTH_WINDOW_SECONDS', 60);
            $this->rateLimiter->hit('auth:' . $path . ':' . $identifier, $max, $window);
            return;
        }

        if (in_array($path, ['/auth/invitations/accept-password', '/auth/invitations/accept-google'], true)) {
            $max = $this->config->getInt('RATE_LIMIT_INVITE_ACCEPT_MAX', 10);
            $window = $this->config->getInt('RATE_LIMIT_INVITE_ACCEPT_WINDOW_SECONDS', 60);
            $this->rateLimiter->hit('invite-accept:' . $path . ':' . $identifier, $max, $window);
            return;
        }

        if ($path === '/me/email-change/request') {
            $max = $this->config->getInt('RATE_LIMIT_EMAIL_CHANGE_REQUEST_MAX', 5);
            $window = $this->config->getInt('RATE_LIMIT_EMAIL_CHANGE_REQUEST_WINDOW_SECONDS', 600);
            $this->rateLimiter->hit('email-change-request:' . $identifier, $max, $window);
            return;
        }

        if ($path === '/me/email-change/verify') {
            $max = $this->config->getInt('RATE_LIMIT_EMAIL_CHANGE_VERIFY_MAX', 10);
            $window = $this->config->getInt('RATE_LIMIT_EMAIL_CHANGE_VERIFY_WINDOW_SECONDS', 600);
            $this->rateLimiter->hit('email-change-verify:' . $identifier, $max, $window);
            return;
        }

        if ($path === '/me/auth/convert-google') {
            $max = $this->config->getInt('RATE_LIMIT_AUTH_MAX', 10);
            $window = $this->config->getInt('RATE_LIMIT_AUTH_WINDOW_SECONDS', 60);
            $this->rateLimiter->hit('convert-google:' . $identifier, $max, $window);
        }
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

    private function logServerError(Request $request, Throwable $e): void
    {
        $message = sprintf(
            '[budget-api] %s %s failed with %s: %s',
            $request->method,
            $request->path,
            $e::class,
            $e->getMessage()
        );

        error_log($message);
    }
}
