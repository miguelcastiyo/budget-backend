<?php

declare(strict_types=1);

use App\Auth\AuthContext;
use App\Core\Config;
use App\Http\HttpException;
use App\Privacy\FinancialPrivacyState;
use App\Privacy\FinancialPrivacyStateService;
use App\Privacy\RecentAuthGuard;
use App\Privacy\VaultCryptoProfile;
use App\Privacy\VaultRepository;
use App\Privacy\VaultService;
use App\Security\AuditLogger;

if (getenv('PRIVACY_PARITY_TEST') !== '1') { fwrite(STDERR, "Refusing encrypted-by-default lifecycle test: set PRIVACY_PARITY_TEST=1\n"); exit(2); }
$dsn = (string) getenv('DB_DSN');
if (!preg_match('/^mysql:.*dbname=([^;]+)/', $dsn, $match) || !str_ends_with($match[1], '_privacy_parity_test')) { fwrite(STDERR, "DB_DSN must point to the dedicated *_privacy_parity_test database\n"); exit(2); }
require __DIR__ . '/../src/bootstrap.php';
$pdo = new PDO($dsn, (string) getenv('DB_USER'), (string) getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$suffix = bin2hex(random_bytes(6));
$email = "encrypted-default-{$suffix}@example.test";
$pdo->prepare("INSERT INTO users (email, display_name, auth_provider, password_hash, email_verified, role, is_active) VALUES (:email, 'Encrypted Default Test', 'password', :password, 1, 'member', 1)")->execute([':email' => $email, ':password' => password_hash('lifecycle-test', PASSWORD_DEFAULT)]);
$userId = (int) $pdo->lastInsertId();

function expectConflict(callable $callback): void { try { $callback(); } catch (HttpException $error) { if ($error->errorCode === 'PRIVACY_STATE_CONFLICT') return; throw $error; } throw new RuntimeException('expected privacy state conflict'); }
function b64url(string $bytes): string { return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); }

try {
    $states = new FinancialPrivacyStateService($pdo);
    if ($states->get($userId) !== FinancialPrivacyState::VAULT_SETUP_REQUIRED) throw new RuntimeException('new users do not default to vault_setup_required');
    $config = Config::load(dirname(__DIR__));
    $service = new VaultService($pdo, new VaultRepository($pdo), $states, new RecentAuthGuard($config), new AuditLogger($pdo));
    $auth = new AuthContext(['id' => $userId, 'role' => 'member', 'session_created_at' => gmdate('Y-m-d H:i:s')], 'session', "lifecycle-{$suffix}");
    $payload = [
        'crypto_profile_version' => 1,
        'passphrase_wrap' => ['kdf' => 'PBKDF2', 'kdf_hash' => 'SHA-256', 'iterations' => VaultCryptoProfile::KDF_ITERATIONS, 'salt' => b64url(str_repeat('s', 32)), 'wrap_algorithm' => 'AES-KW', 'wrapped_vault_key' => b64url(str_repeat('p', 40))],
        'recovery_wrap' => ['wrap_algorithm' => 'AES-KW', 'wrapped_vault_key' => b64url(str_repeat('r', 40))],
    ];
    $service->initialize($auth, $payload);
    if ($states->get($userId) !== FinancialPrivacyState::ENCRYPTED) throw new RuntimeException('Vault setup did not transition to encrypted');
    $sync = $pdo->prepare('SELECT next_sync_sequence FROM encrypted_record_sync_state WHERE user_id = :id'); $sync->execute([':id' => $userId]);
    if ((int) $sync->fetchColumn() !== 0) throw new RuntimeException('encrypted sync state was not initialized');
    expectConflict(fn() => $states->transition($userId, FinancialPrivacyState::VAULT_SETUP_REQUIRED));
    echo "Encrypted-by-default lifecycle tests passed\n";
} finally {
    $pdo->prepare('DELETE FROM audit_logs WHERE actor_user_id = :id')->execute([':id' => $userId]);
    $pdo->prepare('DELETE FROM encrypted_record_sync_state WHERE user_id = :id')->execute([':id' => $userId]);
    $pdo->prepare('DELETE FROM user_financial_vaults WHERE user_id = :id')->execute([':id' => $userId]);
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $userId]);
}
