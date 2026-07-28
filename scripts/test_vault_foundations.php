<?php

declare(strict_types=1);

use App\Auth\AuthContext;
use App\Controllers\DeviceController;
use App\Core\Config;
use App\Http\HttpException;
use App\Http\Request;
use App\Privacy\FinancialPrivacyStateService;
use App\Privacy\RecentAuthGuard;
use App\Privacy\VaultCryptoProfile;
use App\Privacy\VaultRepository;
use App\Privacy\VaultService;
use App\Security\AuditLogger;

if (getenv('PRIVACY_PARITY_TEST') !== '1') {
    fwrite(STDERR, "Refusing Vault foundation test: set PRIVACY_PARITY_TEST=1\n");
    exit(2);
}
$dsn = (string) getenv('DB_DSN');
if (!preg_match('/^mysql:.*dbname=([^;]+)/', $dsn, $match) || !str_ends_with($match[1], '_privacy_parity_test')) {
    fwrite(STDERR, "DB_DSN must point to the dedicated *_privacy_parity_test database\n");
    exit(2);
}

require __DIR__ . '/../src/bootstrap.php';
$pdo = new PDO($dsn, (string) getenv('DB_USER'), (string) getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$suffix = bin2hex(random_bytes(6));
$email = 'phase2-vault-' . $suffix . '@example.test';
$insert = $pdo->prepare("INSERT INTO users (email, display_name, auth_provider, password_hash, email_verified, role, is_active, financial_privacy_state) VALUES (:email, 'Phase 2 Vault Test', 'password', :password_hash, 1, 'member', 1, 'legacy_plaintext')");
$insert->execute([':email' => $email, ':password_hash' => password_hash('phase2-test-only', PASSWORD_DEFAULT)]);
$userId = (int) $pdo->lastInsertId();

function b64url(string $bytes): string { return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); }
function expectFailure(callable $callback, int $status, string $code): void {
    try { $callback(); } catch (HttpException $e) { if ($e->status !== $status || $e->errorCode !== $code) throw new RuntimeException("expected {$status}/{$code}, got {$e->status}/{$e->errorCode}"); return; }
    throw new RuntimeException("expected {$status}/{$code} failure");
}

try {
    $config = Config::load(dirname(__DIR__));
    $request = new Request('POST', '/api/v1/me/vault', '', [], [], [], [], ['User-Agent' => 'phase2-vault-test']);
    $auth = new AuthContext(['id' => $userId, 'role' => 'member', 'session_created_at' => gmdate('Y-m-d H:i:s')], 'session', 'phase2-session-' . $suffix);
    $service = new VaultService($pdo, new VaultRepository($pdo), new FinancialPrivacyStateService($pdo), new RecentAuthGuard($config), new AuditLogger($pdo));
    $payload = [
        'crypto_profile_version' => 1,
        'passphrase_wrap' => ['kdf' => 'PBKDF2', 'kdf_hash' => 'SHA-256', 'iterations' => VaultCryptoProfile::KDF_ITERATIONS, 'salt' => b64url(str_repeat('s', 32)), 'wrap_algorithm' => 'AES-KW', 'wrapped_vault_key' => b64url(str_repeat('p', 40))],
        'recovery_wrap' => ['wrap_algorithm' => 'AES-KW', 'wrapped_vault_key' => b64url(str_repeat('r', 40))],
    ];
    $created = $service->initialize($auth, $payload, $request);
    if (!str_starts_with($created['vault_id'], 'vault_') || $created['passphrase']['salt'] !== $payload['passphrase_wrap']['salt']) throw new RuntimeException('created metadata is incorrect');
    $same = $service->initialize($auth, $payload);
    if (($same['idempotent'] ?? false) !== true || $same['vault_id'] !== $created['vault_id']) throw new RuntimeException('exact retry was not idempotent');
    $conflict = $payload;
    $conflict['passphrase_wrap']['salt'] = b64url(str_repeat('x', 32));
    expectFailure(fn() => $service->initialize($auth, $conflict), 409, 'VAULT_ALREADY_INITIALIZED');
    $invalid = $payload;
    $invalid['passphrase_wrap']['salt'] = base64_encode(str_repeat('b', 32));
    expectFailure(fn() => $service->initialize($auth, $invalid), 422, 'VAULT_PAYLOAD_INVALID');
    expectFailure(fn() => $service->initialize(new AuthContext(['id' => $userId], 'api_key', null, 'phase2-key'), $payload), 403, 'RECENT_AUTH_REQUIRED');
    $metadata = $service->metadata($userId);
    if ($metadata['vault_id'] !== $created['vault_id'] || isset($metadata['recovery_secret'])) throw new RuntimeException('metadata leaked unsafe value');
    $rotatedPassphrase = $payload;
    $rotatedPassphrase['passphrase_wrap']['salt'] = b64url(str_repeat('t', 32));
    $rotatedPassphrase['passphrase_wrap']['wrapped_vault_key'] = b64url(str_repeat('q', 40));
    $afterPassphrase = $service->replacePassphrase($auth, ['passphrase_wrap' => $rotatedPassphrase['passphrase_wrap']], $request);
    if ($afterPassphrase['passphrase']['salt'] !== $rotatedPassphrase['passphrase_wrap']['salt'] || $afterPassphrase['recovery']['wrapped_vault_key'] !== $created['recovery']['wrapped_vault_key']) throw new RuntimeException('passphrase wrapper rotation changed the wrong material');
    $rotatedRecovery = ['wrap_algorithm' => 'AES-KW', 'wrapped_vault_key' => b64url(str_repeat('z', 40))];
    $afterRecovery = $service->replaceRecovery($auth, ['recovery_wrap' => $rotatedRecovery], $request);
    if ($afterRecovery['recovery']['wrapped_vault_key'] !== $rotatedRecovery['wrapped_vault_key'] || $afterRecovery['passphrase']['salt'] !== $rotatedPassphrase['passphrase_wrap']['salt']) throw new RuntimeException('recovery wrapper rotation changed the wrong material');
    expectFailure(fn() => $service->replacePassphrase(new AuthContext(['id' => $userId], 'api_key', null, 'phase2-key'), ['passphrase_wrap' => $rotatedPassphrase['passphrase_wrap']]), 403, 'RECENT_AUTH_REQUIRED');
    $currentSession = (string) $auth->sessionId;
    $currentSecret = 'device-current-secret';
    $pdo->prepare("INSERT INTO user_sessions (session_id, user_id, session_secret_hash, csrf_token_hash, client_type, user_agent, created_at, expires_at) VALUES (:session_id, :user_id, :secret_hash, :csrf_hash, 'web', 'Mozilla/5.0 Safari/Phase7', UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))")->execute([':session_id' => $currentSession, ':user_id' => $userId, ':secret_hash' => hash('sha256', $currentSecret), ':csrf_hash' => hash('sha256', 'device-csrf')]);
    $otherSession = 'phase7-device-' . $suffix;
    $pdo->prepare("INSERT INTO user_sessions (session_id, user_id, session_secret_hash, csrf_token_hash, client_type, user_agent, created_at, expires_at) VALUES (:session_id, :user_id, :secret_hash, :csrf_hash, 'web', 'Mozilla/5.0 Chrome/Phase7', UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))")->execute([':session_id' => $otherSession, ':user_id' => $userId, ':secret_hash' => hash('sha256', 'device-secret'), ':csrf_hash' => hash('sha256', 'device-csrf')]);
    $devices = new DeviceController($pdo, new App\Auth\AuthService($pdo, $config), new RecentAuthGuard($config));
    $deviceHeaders = ['Authorization' => 'Session ' . $currentSession . '.' . $currentSecret, 'User-Agent' => 'phase7-device-test'];
    $deviceList = json_decode($devices->list(new Request('GET', '/me/devices', '', [], [], [], [], $deviceHeaders))->body, true);
    if (!is_array($deviceList) || count($deviceList['items'] ?? []) < 1) throw new RuntimeException('device listing is incorrect');
    $revoked = json_decode($devices->revoke(new Request('DELETE', '/me/devices/' . $otherSession, '', [], [], [], [], $deviceHeaders), ['session_id' => $otherSession])->body, true);
    if (($revoked['revoked'] ?? false) !== true) throw new RuntimeException('device revocation is incorrect');
    $audit = $pdo->prepare("SELECT action, target_type, target_id, metadata FROM audit_logs WHERE actor_user_id = :user_id AND action = 'vault.initialized' ORDER BY id DESC LIMIT 1");
    $audit->execute([':user_id' => $userId]);
    $event = $audit->fetch();
    if (!is_array($event) || $event['target_id'] !== $created['vault_id'] || str_contains((string) $event['metadata'], 'wrapped') || str_contains((string) $event['metadata'], 'recovery')) throw new RuntimeException('unsafe audit event');
    $columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_financial_vaults'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['passphrase', 'recovery_secret', 'raw_vault_key', 'plaintext', 'ciphertext'] as $forbidden) if (in_array($forbidden, $columns, true)) throw new RuntimeException("forbidden Vault column exists: {$forbidden}");
    echo "Vault foundation contract tests passed\n";
} finally {
    $pdo->prepare('DELETE FROM audit_logs WHERE actor_user_id = :id')->execute([':id' => $userId]);
    if (isset($otherSession)) $pdo->prepare('DELETE FROM user_sessions WHERE session_id = :session_id')->execute([':session_id' => $otherSession]);
    if (isset($currentSession)) $pdo->prepare('DELETE FROM user_sessions WHERE session_id = :session_id')->execute([':session_id' => $currentSession]);
    $pdo->prepare('DELETE FROM user_financial_vaults WHERE user_id = :id')->execute([':id' => $userId]);
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $userId]);
}
