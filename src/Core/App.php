<?php

declare(strict_types=1);

namespace App\Core;

use App\Auth\AuthService;
use App\Auth\GoogleTokenVerifier;
use App\Budget\BudgetSettingsResolver;
use App\Controllers\AuditLogController;
use App\Controllers\AuthController;
use App\Controllers\BudgetSettingsController;
use App\Controllers\FundController;
use App\Controllers\HealthController;
use App\Controllers\ImportExportController;
use App\Controllers\MasterApiKeyController;
use App\Controllers\MonthCloseoutController;
use App\Controllers\MonthOverviewController;
use App\Controllers\MetricsController;
use App\Controllers\ProfileController;
use App\Controllers\PrivacyController;
use App\Controllers\VaultController;
use App\Controllers\QuickUnlockController;
use App\Controllers\EncryptedRecordController;
use App\Controllers\RecurringExpenseController;
use App\Controllers\SavingsPlanController;
use App\Controllers\TaxonomyController;
use App\Controllers\TransactionController;
use App\Database\Connection;
use App\Funds\FundBalanceService;
use App\Funds\FundCloseoutIntegrationService;
use App\Funds\FundRepository;
use App\Funds\FundService;
use App\Funds\FundTransactionIntegrationService;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\ImportExport\CsvExportService;
use App\ImportExport\CsvImportCommitter;
use App\ImportExport\CsvImportMapper;
use App\ImportExport\CsvImportReader;
use App\ImportExport\CsvImportService;
use App\ImportExport\DataRunRepository;
use App\ImportExport\TaxonomyImportRepository;
use App\Mail\Mailer;
use App\MonthCloseout\MonthCloseoutRepository;
use App\MonthCloseout\MonthCloseoutService;
use App\Monitoring\ErrorReporter;
use App\Monitoring\StructuredLogger;
use App\Overview\MonthOverviewService;
use App\Security\AuditLogger;
use App\Recurring\RecurringExpenseService;
use App\Security\RateLimiter;
use App\Privacy\FinancialPrivacyStateService;
use App\Privacy\FinancialRevisionService;
use App\Privacy\FinancialWritePolicy;
use App\Privacy\FinancialReadPolicy;
use App\Privacy\MigrationSnapshotService;
use App\Privacy\MigrationStagingRepository;
use App\Privacy\PrivacyCleanupRepository;
use App\Privacy\PrivacyMigrationRepository;
use App\Privacy\RecentAuthGuard;
use App\Privacy\VaultRepository;
use App\Privacy\VaultService;
use App\Privacy\QuickUnlockRepository;
use App\Privacy\QuickUnlockService;
use App\Devices\DeviceLifecycleService;
use App\Privacy\EncryptedRecordRepository;
use App\Privacy\EncryptedRecordService;
use App\Savings\SavingsPlanService;
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
        $recurring = new RecurringExpenseService($pdo);
        $budgetSettingsResolver = new BudgetSettingsResolver($pdo);
        $savingsPlanService = new SavingsPlanService($pdo, $budgetSettingsResolver);
        $monthOverviewService = new MonthOverviewService($pdo, $budgetSettingsResolver, $savingsPlanService);
        $monthCloseoutRepository = new MonthCloseoutRepository($pdo);
        $fundRepository = new FundRepository($pdo);
        $fundBalanceService = new FundBalanceService($fundRepository);
        $fundTransactionIntegrationService = new FundTransactionIntegrationService($pdo, $fundRepository);
        $fundCloseoutIntegrationService = new FundCloseoutIntegrationService($pdo, $fundRepository);
        $fundService = new FundService($pdo, $fundRepository, $fundBalanceService, $fundTransactionIntegrationService);
        $monthCloseoutService = new MonthCloseoutService($pdo, $config, $budgetSettingsResolver, $monthCloseoutRepository, $fundCloseoutIntegrationService);
        $mailer = new Mailer($config);
        $rateLimiter = new RateLimiter($config);
        $structuredLogger = new StructuredLogger($config);
        $errorReporter = new ErrorReporter($config, $structuredLogger);
        $auditLogger = new AuditLogger($pdo);
        $csvImportMapperBase = new CsvImportMapper();
        $taxonomyImportRepository = new TaxonomyImportRepository($pdo, $csvImportMapperBase);
        $csvImportMapper = new CsvImportMapper($taxonomyImportRepository);
        $dataRunRepository = new DataRunRepository($pdo);
        $csvImportReader = new CsvImportReader($config, $csvImportMapper);
        $csvImportCommitter = new CsvImportCommitter($pdo, $taxonomyImportRepository, $csvImportMapper);
        $csvImportService = new CsvImportService($pdo, $csvImportReader, $csvImportMapper, $csvImportCommitter, $dataRunRepository);
        $csvExportService = new CsvExportService($pdo, $csvImportMapper, $dataRunRepository);
        $googleTokenVerifier = new GoogleTokenVerifier($config, $structuredLogger);
        $authController = new AuthController($pdo, $auth, $googleTokenVerifier, $mailer, $config, $auditLogger);
        $auditLogController = new AuditLogController($pdo, $auth);
        $budgetSettingsController = new BudgetSettingsController($pdo, $auth, $budgetSettingsResolver);
        $importExportController = new ImportExportController($auth, $auditLogger, $csvImportService, $csvExportService, $dataRunRepository, $csvImportMapper);
        $recurringExpenseController = new RecurringExpenseController($pdo, $auth, $recurring);
        $savingsPlanController = new SavingsPlanController($auth, $savingsPlanService);
        $profileController = new ProfileController($pdo, $auth, $googleTokenVerifier, $mailer, $config, $auditLogger, $budgetSettingsResolver);
        $masterApiKeyController = new MasterApiKeyController($pdo, $auth, $auditLogger, $config);
        $taxonomyController = new TaxonomyController($pdo, $auth);
        $transactionController = new TransactionController($pdo, $auth, $recurring, $fundTransactionIntegrationService);
        $fundController = new FundController($auth, $fundService);
        $monthOverviewController = new MonthOverviewController($auth, $monthOverviewService);
        $monthCloseoutController = new MonthCloseoutController($auth, $monthCloseoutService);
        $metricsController = new MetricsController($pdo, $auth, $recurring, $budgetSettingsResolver);
        $healthController = new HealthController($structuredLogger);
        $financialStates = new FinancialPrivacyStateService($pdo);
        $financialRevisions = new FinancialRevisionService($pdo);
        $privacyMigrations = new PrivacyMigrationRepository($pdo);
        $privacyCleanup = new PrivacyCleanupRepository($pdo);
        $recentAuth = new RecentAuthGuard($config);
        $vaultRepository = new VaultRepository($pdo);
        $privacyMigrationService = new \App\Privacy\PrivacyMigrationService($pdo, $financialStates, $financialRevisions, $privacyMigrations);
        $migrationStaging = new MigrationStagingRepository($pdo);
        $privacyCutover = new \App\Privacy\PrivacyCutoverService($pdo, $financialStates, $financialRevisions, $privacyMigrations, $migrationStaging, $vaultRepository, $privacyCleanup);
        $privacyController = new PrivacyController($auth, $financialStates, $financialRevisions, $privacyMigrations, $privacyCleanup, $privacyMigrationService, new MigrationSnapshotService($pdo, $financialRevisions, $privacyMigrations), $migrationStaging, $vaultRepository, $recentAuth, $privacyCutover);
        $vaultController = new VaultController($auth, new VaultService($pdo, $vaultRepository, $financialStates, $recentAuth, $auditLogger));
        $quickUnlockController = new QuickUnlockController($auth, new QuickUnlockService($pdo, $config, $financialStates, $vaultRepository, $recentAuth, new QuickUnlockRepository($pdo), $auditLogger));
        $deviceLifecycle = new DeviceLifecycleService($pdo, new QuickUnlockRepository($pdo), $auditLogger);
        $deviceController = new \App\Controllers\DeviceController($pdo, $auth, $recentAuth, $deviceLifecycle);
        $encryptedRecordController = new EncryptedRecordController($auth, new EncryptedRecordService($pdo, new EncryptedRecordRepository($pdo), new VaultRepository($pdo), $financialStates, $auditLogger));
        $writePolicy = new FinancialWritePolicy($financialStates);
        $readPolicy = new FinancialReadPolicy($financialStates);

        $router = new Router();

        $add = static function (string $method, string $path, callable $handler) use ($router): void {
            $router->add($method, '/api/v1' . $path, $handler);
            $router->add($method, $path, $handler);
        };

        $financialMutation = static function (callable $handler) use ($auth, $writePolicy): callable {
            return static function (Request $request, array $params = []) use ($auth, $writePolicy, $handler): Response {
                $writePolicy->requirePlaintextWriteAllowed($auth->requireAuth($request)->userId());
                return $handler($request, $params);
            };
        };

        $financialRead = static function (callable $handler) use ($auth, $readPolicy): callable {
            return static function (Request $request, array $params = []) use ($auth, $readPolicy, $handler): Response {
                $readPolicy->requireLegacyReadAllowed($auth->requireAuth($request)->userId());
                return $handler($request, $params);
            };
        };

        $add('GET', '/health', fn(Request $request) => $healthController($request));

        $add('GET', '/auth/invitations', fn(Request $request) => $authController->listInvitations($request));
        $add('POST', '/auth/invitations', fn(Request $request) => $authController->createInvitation($request));
        $add('DELETE', '/auth/invitations/{invite_id}', fn(Request $request, array $params) => $authController->revokeInvitation($request, $params));
        $add('DELETE', '/auth/invitations/{invite_id}/account', fn(Request $request, array $params) => $authController->deleteInvitedAccount($request, $params));
        $add('GET', '/auth/invitations/preview', fn(Request $request) => $authController->previewInvitation($request));
        $add('POST', '/auth/invitations/accept-password', fn(Request $request) => $authController->acceptInvitationPassword($request));
        $add('POST', '/auth/invitations/accept-google', fn(Request $request) => $authController->acceptInvitationGoogle($request));
        $add('POST', '/auth/sessions/password', fn(Request $request) => $authController->signInPassword($request));
        $add('POST', '/auth/sessions/google', fn(Request $request) => $authController->signInGoogle($request));
        $add('DELETE', '/auth/sessions/current', fn(Request $request) => $authController->signOutCurrentSession($request));
        $add('POST', '/auth/password-reset/request', fn(Request $request) => $authController->requestPasswordReset($request));
        $add('POST', '/auth/password-reset/confirm', fn(Request $request) => $authController->confirmPasswordReset($request));

        $add('GET', '/me', fn(Request $request) => $profileController->getMe($request));
        $add('GET', '/me/privacy', fn(Request $request) => $privacyController->status($request));
        $add('POST', '/me/privacy/migration', fn(Request $request) => $privacyController->start($request));
        $add('GET', '/me/privacy/migration/{migration_id}', fn(Request $request, array $params) => $privacyController->migrationStatus($request, $params));
        $add('GET', '/me/privacy/migration/{migration_id}/snapshot', fn(Request $request, array $params) => $privacyController->snapshot($request, $params));
        $add('PUT', '/me/privacy/migration/{migration_id}/manifest', fn(Request $request, array $params) => $privacyController->putManifest($request, $params));
        $add('PUT', '/me/privacy/migration/{migration_id}/records/{record_id}', fn(Request $request, array $params) => $privacyController->putRecord($request, $params));
        $add('POST', '/me/privacy/migration/{migration_id}/verify', fn(Request $request, array $params) => $privacyController->verify($request, $params));
        $add('POST', '/me/privacy/migration/{migration_id}/cutover', fn(Request $request, array $params) => $privacyController->cutover($request, $params));
        $add('POST', '/me/privacy/migration/{migration_id}/cancel', fn(Request $request, array $params) => $privacyController->cancel($request, $params));
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

        $add('GET', '/me/master-api-keys', fn(Request $request) => $masterApiKeyController->listKeys($request));
        $add('POST', '/me/master-api-keys', fn(Request $request) => $masterApiKeyController->create($request));
        $add('DELETE', '/me/master-api-keys/{api_key_id}', fn(Request $request, array $params) => $masterApiKeyController->revoke($request, $params));
        $add('GET', '/me/audit-logs', fn(Request $request) => $auditLogController->list($request));

        // Compatibility boundary: these financial routes remain registered
        // for migration and rollback support. Every read/write must pass a
        // privacy policy gate; Phase 2 must not remove routes or tables before
        // evidence closure.
        $add('GET', '/me/budget-settings', $financialRead(fn(Request $request) => $budgetSettingsController->get($request)));
        $add('GET', '/me/budget-settings/versions', $financialRead(fn(Request $request) => $budgetSettingsController->versions($request)));
        $add('PUT', '/me/budget-settings', $financialMutation(fn(Request $request) => $budgetSettingsController->upsert($request)));

        $add('GET', '/me/tags', $financialRead(fn(Request $request) => $taxonomyController->listTags($request)));
        $add('GET', '/me/tags/quick-picks', $financialRead(fn(Request $request) => $taxonomyController->tagQuickPicks($request)));
        $add('POST', '/me/tags', $financialMutation(fn(Request $request) => $taxonomyController->createTag($request)));
        $add('PATCH', '/me/tags/{tag_id}', $financialMutation(fn(Request $request, array $params) => $taxonomyController->updateTag($request, $params)));
        $add('DELETE', '/me/tags/{tag_id}', $financialMutation(fn(Request $request, array $params) => $taxonomyController->deleteTag($request, $params)));

        $add('GET', '/me/cards', $financialRead(fn(Request $request) => $taxonomyController->listCards($request)));
        $add('POST', '/me/cards', $financialMutation(fn(Request $request) => $taxonomyController->createCard($request)));
        $add('PATCH', '/me/cards/{card_id}', $financialMutation(fn(Request $request, array $params) => $taxonomyController->updateCard($request, $params)));
        $add('DELETE', '/me/cards/{card_id}', $financialMutation(fn(Request $request, array $params) => $taxonomyController->deleteCard($request, $params)));

        $add('GET', '/me/contexts', $financialRead(fn(Request $request) => $taxonomyController->listContexts($request)));
        $add('POST', '/me/contexts', $financialMutation(fn(Request $request) => $taxonomyController->createContext($request)));
        $add('PATCH', '/me/contexts/{context_id}', $financialMutation(fn(Request $request, array $params) => $taxonomyController->updateContext($request, $params)));
        $add('DELETE', '/me/contexts/{context_id}', $financialMutation(fn(Request $request, array $params) => $taxonomyController->deleteContext($request, $params)));

        $add('GET', '/me/recurring-expenses', $financialRead(fn(Request $request) => $recurringExpenseController->list($request)));
        $add('POST', '/me/recurring-expenses', $financialMutation(fn(Request $request) => $recurringExpenseController->create($request)));
        $add('GET', '/me/recurring-expenses/{recurring_expense_id}/series', $financialRead(fn(Request $request, array $params) => $recurringExpenseController->series($request, $params)));
        $add('POST', '/me/recurring-expenses/{recurring_expense_id}/schedule-change', $financialMutation(fn(Request $request, array $params) => $recurringExpenseController->scheduleChange($request, $params)));
        $add('PATCH', '/me/recurring-expenses/{recurring_expense_id}', $financialMutation(fn(Request $request, array $params) => $recurringExpenseController->update($request, $params)));
        $add('DELETE', '/me/recurring-expenses/{recurring_expense_id}', $financialMutation(fn(Request $request, array $params) => $recurringExpenseController->delete($request, $params)));

        $add('GET', '/me/transactions', $financialRead(fn(Request $request) => $transactionController->list($request)));
        $add('GET', '/me/transactions/suggestions', $financialRead(fn(Request $request) => $transactionController->suggestions($request)));
        $add('POST', '/me/transactions', $financialMutation(fn(Request $request) => $transactionController->create($request)));
        $add('PATCH', '/me/transactions/{transaction_id}', $financialMutation(fn(Request $request, array $params) => $transactionController->update($request, $params)));
        $add('DELETE', '/me/transactions/{transaction_id}', $financialMutation(fn(Request $request, array $params) => $transactionController->delete($request, $params)));

        $add('GET', '/me/months/{month}/savings-plan', $financialRead(fn(Request $request, array $params) => $savingsPlanController->get($request, $params)));
        $add('PUT', '/me/months/{month}/savings-plan', $financialMutation(fn(Request $request, array $params) => $savingsPlanController->replace($request, $params)));

        $add('GET', '/me/funds', $financialRead(fn(Request $request) => $fundController->list($request)));
        $add('POST', '/me/funds', $financialMutation(fn(Request $request) => $fundController->create($request)));
        $add('GET', '/me/funds/closeout-summary', $financialRead(fn(Request $request) => $fundController->closeoutSummary($request)));
        $add('GET', '/me/funds/{fund_id}', $financialRead(fn(Request $request, array $params) => $fundController->get($request, $params)));
        $add('PATCH', '/me/funds/{fund_id}', $financialMutation(fn(Request $request, array $params) => $fundController->update($request, $params)));
        $add('POST', '/me/funds/{fund_id}/archive', $financialMutation(fn(Request $request, array $params) => $fundController->archive($request, $params)));
        $add('POST', '/me/funds/{fund_id}/restore', $financialMutation(fn(Request $request, array $params) => $fundController->restore($request, $params)));
        $add('GET', '/me/funds/{fund_id}/entries', $financialRead(fn(Request $request, array $params) => $fundController->entries($request, $params)));
        $add('POST', '/me/funds/{fund_id}/entries', $financialMutation(fn(Request $request, array $params) => $fundController->createEntry($request, $params)));
        $add('PATCH', '/me/funds/{fund_id}/entries/{entry_id}', $financialMutation(fn(Request $request, array $params) => $fundController->updateEntry($request, $params)));
        $add('DELETE', '/me/funds/{fund_id}/entries/{entry_id}', $financialMutation(fn(Request $request, array $params) => $fundController->deleteEntry($request, $params)));

        $add('GET', '/me/months/{month}/overview', $financialRead(fn(Request $request, array $params) => $monthOverviewController->overview($request, $params)));
        $add('GET', '/me/month-closeouts', $financialRead(fn(Request $request) => $monthCloseoutController->list($request)));
        $add('GET', '/me/month-closeouts/{month}', $financialRead(fn(Request $request, array $params) => $monthCloseoutController->get($request, $params)));
        $add('POST', '/me/month-closeouts/{month}/close', $financialMutation(fn(Request $request, array $params) => $monthCloseoutController->close($request, $params)));
        $add('PATCH', '/me/month-closeouts/{month}', $financialMutation(fn(Request $request, array $params) => $monthCloseoutController->update($request, $params)));
        $add('POST', '/me/month-closeouts/{month}/reopen', $financialMutation(fn(Request $request, array $params) => $monthCloseoutController->reopen($request, $params)));

        $add('GET', '/me/transactions/export.csv', $financialRead(fn(Request $request) => $importExportController->exportCsv($request)));
        $add('POST', '/me/transactions/import.csv', $financialMutation(fn(Request $request) => $importExportController->importCsv($request)));
        $add('DELETE', '/me/imports/{import_run_id}/transactions', $financialMutation(fn(Request $request, array $params) => $importExportController->rollbackImport($request, $params)));
        $add('GET', '/me/data-runs', $financialRead(fn(Request $request) => $importExportController->listDataRuns($request)));

        $add('GET', '/me/metrics/tags', $financialRead(fn(Request $request) => $metricsController->tags($request)));
        $add('GET', '/me/metrics/categories', $financialRead(fn(Request $request) => $metricsController->categories($request)));
        $add('GET', '/me/dashboard', $financialRead(fn(Request $request) => $metricsController->dashboard($request)));
        $add('GET', '/me/metrics/insights', $financialRead(fn(Request $request) => $metricsController->insights($request)));

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

        if ($method === 'POST' && in_array($path, ['/auth/sessions/password', '/auth/sessions/google'], true)) {
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

        if ($method === 'POST' && $path === '/me/master-api-keys') {
            $this->hitAuthenticatedRateLimit(
                $request,
                'api-key-create',
                $this->config->getInt('RATE_LIMIT_API_KEY_CREATE_MAX', 5),
                $this->config->getInt('RATE_LIMIT_API_KEY_CREATE_WINDOW_SECONDS', 3600)
            );
            return;
        }

        if ($method === 'DELETE' && preg_match('#^/me/master-api-keys/[^/]+$#', $path) === 1) {
            $this->hitAuthenticatedRateLimit(
                $request,
                'api-key-revoke',
                $this->config->getInt('RATE_LIMIT_API_KEY_REVOKE_MAX', 20),
                $this->config->getInt('RATE_LIMIT_API_KEY_REVOKE_WINDOW_SECONDS', 3600)
            );
            return;
        }

        if ($method === 'DELETE' && preg_match('#^/me/devices/[^/]+$#', $path) === 1) {
            $this->hitAuthenticatedRateLimit($request, 'device-removal', $this->config->getInt('RATE_LIMIT_DEVICE_REMOVAL_MAX', 10), $this->config->getInt('RATE_LIMIT_DEVICE_REMOVAL_WINDOW_SECONDS', 600));
            return;
        }

        if ($method === 'POST' && $path === '/me/transactions/import.csv') {
            $this->hitAuthenticatedRateLimit(
                $request,
                'csv-import',
                $this->config->getInt('RATE_LIMIT_CSV_IMPORT_MAX', 10),
                $this->config->getInt('RATE_LIMIT_CSV_IMPORT_WINDOW_SECONDS', 3600)
            );
            return;
        }

        if ($method === 'DELETE' && preg_match('#^/me/imports/[0-9]+/transactions$#', $path) === 1) {
            $this->hitAuthenticatedRateLimit(
                $request,
                'csv-import-rollback',
                $this->config->getInt('RATE_LIMIT_CSV_IMPORT_ROLLBACK_MAX', 20),
                $this->config->getInt('RATE_LIMIT_CSV_IMPORT_ROLLBACK_WINDOW_SECONDS', 3600)
            );
            return;
        }

        if ($method === 'GET' && $path === '/me/transactions/export.csv') {
            $this->hitAuthenticatedRateLimit(
                $request,
                'csv-export',
                $this->config->getInt('RATE_LIMIT_CSV_EXPORT_MAX', 30),
                $this->config->getInt('RATE_LIMIT_CSV_EXPORT_WINDOW_SECONDS', 3600)
            );
            return;
        }

        if ($method === 'GET' && (in_array($path, ['/me/metrics/tags', '/me/metrics/categories', '/me/dashboard', '/me/metrics/insights'], true) || preg_match('#^/me/months/[^/]+/overview$#', $path) === 1)) {
            $this->hitAuthenticatedRateLimit(
                $request,
                'metrics',
                $this->config->getInt('RATE_LIMIT_METRICS_MAX', 120),
                $this->config->getInt('RATE_LIMIT_METRICS_WINDOW_SECONDS', 60)
            );
            return;
        }

        if (
            ($method === 'POST' && preg_match('#^/me/month-closeouts/[^/]+/(close|reopen)$#', $path) === 1)
            || ($method === 'PATCH' && preg_match('#^/me/month-closeouts/[^/]+$#', $path) === 1)
        ) {
            $this->hitAuthenticatedRateLimit(
                $request,
                'month-closeout-write',
                $this->config->getInt('RATE_LIMIT_MONTH_CLOSEOUT_WRITE_MAX', 60),
                $this->config->getInt('RATE_LIMIT_MONTH_CLOSEOUT_WRITE_WINDOW_SECONDS', 3600)
            );
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

        $apiKey = trim((string) ($request->header('X-API-Key') ?? ''));
        if ($apiKey !== '') {
            return 'api-key:' . hash('sha256', $apiKey);
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
