<?php

declare(strict_types=1);

use App\Core\Config;
use App\Auth\AuthService;
use App\Database\Connection;
use App\Http\HttpException;
use App\Http\Request;
use App\Controllers\PrivacyController;
use App\Privacy\FinancialPrivacyState;
use App\Privacy\FinancialPrivacyStateService;
use App\Privacy\FinancialRevisionService;
use App\Privacy\PrivacyCleanupRepository;
use App\Privacy\PrivacyMigrationRepository;
use App\Privacy\PrivacyMigrationService;
use App\Privacy\RecentAuthGuard;
use App\Auth\AuthContext;
use App\Support\Str;

if (getenv('PRIVACY_FOUNDATION_TEST') !== '1') {
    fwrite(STDERR, "Refusing privacy foundation test: set PRIVACY_FOUNDATION_TEST=1\n");
    exit(2);
}

$dsn = getenv('DB_DSN') ?: '';
if (!preg_match('/^mysql:.*dbname=([^;]+)/', $dsn, $match) || !preg_match('/_privacy_parity_test$/', $match[1])) {
    fwrite(STDERR, "DB_DSN must point to the dedicated *_privacy_parity_test database\n");
    exit(2);
}

require __DIR__ . '/../src/bootstrap.php';
$pdo = new PDO(
    (string) getenv('DB_DSN'),
    (string) getenv('DB_USER'),
    (string) getenv('DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$suffix = bin2hex(random_bytes(6));
$email = 'phase1-foundation-' . $suffix . '@example.test';
$insert = $pdo->prepare("INSERT INTO users (email, display_name, auth_provider, password_hash, email_verified, role, is_active, financial_privacy_state) VALUES (:email, 'Phase 1 Foundation Test', 'password', :password_hash, 1, 'member', 1, 'legacy_plaintext')");
$insert->execute([':email' => $email, ':password_hash' => password_hash('phase1-test-only', PASSWORD_DEFAULT)]);
$userId = (int) $pdo->lastInsertId();

try {
    $states = new FinancialPrivacyStateService($pdo);
    $revisions = new FinancialRevisionService($pdo);
    $migrations = new PrivacyMigrationRepository($pdo);
    $cleanup = new PrivacyCleanupRepository($pdo);
    $migrationService = new PrivacyMigrationService($pdo, $states, $revisions, $migrations);

    // This script exercises claim ordering on a dedicated test database. Remove
    // leftovers from interrupted local runs so an unrelated job cannot win the
    // claim and invalidate the retry assertion for this scenario.
    $pdo->exec("DELETE FROM financial_privacy_cleanup_jobs");

    if ($states->get($userId) !== FinancialPrivacyState::LEGACY_PLAINTEXT || $revisions->get($userId) !== 0) {
        throw new RuntimeException('new user defaults are incorrect');
    }

    $before = $revisions->get($userId);
    $revisions->increment($userId);
    if ($revisions->get($userId) !== $before + 1) {
        throw new RuntimeException('revision did not increment atomically');
    }

    $prohibited = false;
    try {
        $states->transition($userId, FinancialPrivacyState::ENCRYPTED);
    } catch (HttpException $e) {
        $prohibited = $e->errorCode === 'PRIVACY_STATE_CONFLICT';
    }
    if (!$prohibited) {
        throw new RuntimeException('prohibited direct transition was accepted');
    }

    $migration = $migrationService->createInternal($userId);
    if ($states->get($userId) !== FinancialPrivacyState::MIGRATION_IN_PROGRESS || $migration['status'] !== 'active') {
        throw new RuntimeException('migration start state is incorrect');
    }

    $migrationService->failInternal($userId, (string) $migration['migration_id'], 'FOUNDATION_TEST_FAILURE');
    if ($states->get($userId) !== FinancialPrivacyState::MIGRATION_FAILED) {
        throw new RuntimeException('migration failure state is incorrect');
    }

    $cleanupJob = $cleanup->createPending($userId, (string) $migration['migration_id']);
    if ($cleanupJob['status'] !== 'pending') {
        throw new RuntimeException('cleanup job default state is incorrect');
    }
    $claimed = $cleanup->claimNext(60);
    if ($claimed === null || $claimed['status'] !== 'running' || (int) $claimed['attempt_count'] !== 1) {
        throw new RuntimeException('cleanup job claim is incorrect');
    }
    $cleanup->markRetry((string) $cleanupJob['cleanup_job_id'], 'FOUNDATION_RETRY', gmdate('Y-m-d H:i:s'));
    $claimedAgain = $cleanup->claimNext(60);
    if ($claimedAgain === null || (int) $claimedAgain['attempt_count'] !== 2) {
        throw new RuntimeException('cleanup retry claim is incorrect');
    }
    $cleanup->markCompleted((string) $cleanupJob['cleanup_job_id']);
    $completedJob = $cleanup->getByPublicId($userId, (string) $cleanupJob['cleanup_job_id']);
    if (($completedJob['status'] ?? null) !== 'completed') {
        throw new RuntimeException('cleanup completion is incorrect');
    }

    $recentAuth = new RecentAuthGuard(Config::load(dirname(__DIR__)));
    $recentAuth->requireRecentInteractiveSession(new AuthContext(
        ['id' => $userId, 'session_created_at' => gmdate('Y-m-d H:i:s')],
        'session',
        'foundation-session'
    ));
    $staleRejected = false;
    try {
        $recentAuth->requireRecentInteractiveSession(new AuthContext(
            ['id' => $userId, 'session_created_at' => gmdate('Y-m-d H:i:s', time() - 901)],
            'session',
            'foundation-stale-session'
        ));
    } catch (HttpException $e) {
        $staleRejected = $e->errorCode === 'RECENT_AUTH_REQUIRED';
    }
    if (!$staleRejected) {
        throw new RuntimeException('stale session was accepted');
    }
    $apiKeyRejected = false;
    try {
        $recentAuth->requireRecentInteractiveSession(new AuthContext(['id' => $userId], 'api_key', null, 'foundation-key'));
    } catch (HttpException $e) {
        $apiKeyRejected = $e->errorCode === 'RECENT_AUTH_REQUIRED';
    }
    if (!$apiKeyRejected) {
        throw new RuntimeException('API key passed recent-auth guard');
    }

    $sessionId = 'phase1_' . $suffix;
    $sessionSecret = 'phase1-session-secret';
    $sessionInsert = $pdo->prepare("INSERT INTO user_sessions (session_id, user_id, session_secret_hash, client_type, created_at, expires_at) VALUES (:session_id, :user_id, :secret_hash, 'native', :created_at, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))");
    $sessionInsert->execute([
        ':session_id' => $sessionId,
        ':user_id' => $userId,
        ':secret_hash' => Str::hashSha256($sessionSecret),
        ':created_at' => gmdate('Y-m-d H:i:s'),
    ]);
    $privacyController = new PrivacyController(
        new AuthService($pdo, Config::load(dirname(__DIR__))),
        $states,
        $revisions,
        $migrations,
        $cleanup
    );
    $statusResponse = $privacyController->status(new Request(
        'GET', '/me/privacy', '', [], [], [], [],
        ['Authorization' => 'Session ' . $sessionId . '.' . $sessionSecret]
    ));
    $statusBody = json_decode($statusResponse->body, true);
    if ($statusResponse->status !== 200 || ($statusBody['financial_privacy_state'] ?? null) !== 'migration_failed') {
        throw new RuntimeException('privacy status endpoint response is incorrect');
    }

    echo "Phase 1 privacy foundation tests passed\n";
} finally {
    if (isset($sessionId)) {
        $deleteSession = $pdo->prepare('DELETE FROM user_sessions WHERE session_id = :session_id');
        $deleteSession->execute([':session_id' => $sessionId]);
    }
    $deleteCleanup = $pdo->prepare('DELETE FROM financial_privacy_cleanup_jobs WHERE user_id = :user_id');
    $deleteCleanup->execute([':user_id' => $userId]);
    $deleteMigrations = $pdo->prepare('DELETE FROM financial_privacy_migrations WHERE user_id = :user_id');
    $deleteMigrations->execute([':user_id' => $userId]);
    $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = :user_id');
    $deleteUser->execute([':user_id' => $userId]);
}
