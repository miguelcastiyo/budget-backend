<?php

declare(strict_types=1);

use App\Auth\AuthContext;
use App\Core\Config;
use App\Http\HttpException;
use App\Privacy\FinancialPrivacyStateService;
use App\Privacy\RecentAuthGuard;
use App\Privacy\VaultCryptoProfile;
use App\Privacy\VaultRepository;
use App\Privacy\VaultService;
use App\Security\AuditLogger;

if (getenv('PRIVACY_PARITY_TEST') !== '1') { fwrite(STDERR, "Refusing Vault concurrency test: set PRIVACY_PARITY_TEST=1\n"); exit(2); }
$dsn = (string) getenv('DB_DSN');
if (!preg_match('/^mysql:.*dbname=([^;]+)/', $dsn, $match) || !str_ends_with($match[1], '_privacy_parity_test')) { fwrite(STDERR, "DB_DSN must point to the dedicated *_privacy_parity_test database\n"); exit(2); }
require __DIR__ . '/../src/bootstrap.php';
function vaultRaceConnection(): PDO { return new PDO((string) getenv('DB_DSN'), (string) getenv('DB_USER'), (string) getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); }
function vaultRaceB64(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
function vaultRacePayload(string $variant): array {
    return ['crypto_profile_version' => 1,
        'passphrase_wrap' => ['kdf' => 'PBKDF2', 'kdf_hash' => 'SHA-256', 'iterations' => VaultCryptoProfile::KDF_ITERATIONS, 'salt' => vaultRaceB64(str_repeat($variant, 32)), 'wrap_algorithm' => 'AES-KW', 'wrapped_vault_key' => vaultRaceB64(str_repeat('p', 40))],
        'recovery_wrap' => ['wrap_algorithm' => 'AES-KW', 'wrapped_vault_key' => vaultRaceB64(str_repeat('r', 40))]];
}
if (($argv[1] ?? '') === 'worker') {
    [$unused, $mode, $user, $directory, $index, $variant] = $argv;
    $pdo = vaultRaceConnection();
    $service = new VaultService($pdo, new VaultRepository($pdo), new FinancialPrivacyStateService($pdo), new RecentAuthGuard(Config::load(dirname(__DIR__))), new AuditLogger($pdo));
    $auth = new AuthContext(['id' => (int) $user, 'role' => 'member', 'session_created_at' => gmdate('Y-m-d H:i:s')], 'session', 'vault-race-' . $index);
    file_put_contents($directory . '/ready-' . $index, 'ready');
    $deadline = microtime(true) + 20;
    while (!is_file($directory . '/start')) { if (microtime(true) > $deadline) exit(3); usleep(10000); }
    try { $result = $service->initialize($auth, vaultRacePayload($variant)); echo json_encode(['status' => 'ok', 'vault_id' => $result['vault_id'], 'idempotent' => $result['idempotent'] ?? false]); }
    catch (HttpException $error) { echo json_encode(['status' => 'error', 'code' => $error->errorCode]); }
    exit(0);
}
$pdo = vaultRaceConnection();
$userIds = [];
$directory = sys_get_temp_dir() . '/budget-vault-race-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) throw new RuntimeException('Unable to create race barrier');
try {
    foreach (['identical', 'different'] as $scenario) {
        foreach (['ready-0', 'ready-1', 'start'] as $file) @unlink($directory . '/' . $file);
        $pdo->prepare("INSERT INTO users (email, display_name, email_verified, role, is_active, financial_privacy_state) VALUES (:email, 'Vault race', 1, 'member', 1, 'vault_setup_required')")->execute([':email' => 'vault-race-' . bin2hex(random_bytes(8)) . '@example.test']);
        $userId = (int) $pdo->lastInsertId(); $userIds[] = $userId;
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE'); $lock->execute([':id' => $userId]);
        $processes = [];
        for ($i = 0; $i < 2; $i++) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __FILE__, 'worker', (string) $userId, $directory, (string) $i, $scenario === 'different' && $i === 1 ? 'b' : 'a'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Unable to start concurrent worker');
            fclose($pipes[0]); $processes[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 20;
        while (!is_file($directory . '/ready-0') || !is_file($directory . '/ready-1')) { if (microtime(true) > $deadline) throw new RuntimeException('Workers did not reach barrier'); usleep(10000); }
        file_put_contents($directory . '/start', 'go'); usleep(250000); $pdo->commit();
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($process) !== 0 || $errors !== '') throw new RuntimeException('Concurrent worker failed');
            $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        }
        $ok = array_values(array_filter($results, fn($item) => $item['status'] === 'ok'));
        $errors = array_values(array_filter($results, fn($item) => $item['status'] === 'error'));
        if ($scenario === 'identical' && (count($ok) !== 2 || $ok[0]['vault_id'] !== $ok[1]['vault_id'] || (int) $ok[0]['idempotent'] + (int) $ok[1]['idempotent'] !== 1)) throw new RuntimeException('Identical concurrent requests did not converge on one Vault');
        if ($scenario === 'different' && (count($ok) !== 1 || count($errors) !== 1 || $errors[0]['code'] !== 'VAULT_ALREADY_INITIALIZED')) throw new RuntimeException('Different concurrent requests were not rejected safely');
        $count = $pdo->prepare('SELECT COUNT(*) FROM user_financial_vaults WHERE user_id = :id'); $count->execute([':id' => $userId]);
        if ((int) $count->fetchColumn() !== 1 || (new FinancialPrivacyStateService($pdo))->get($userId) !== App\Privacy\FinancialPrivacyState::ENCRYPTED) throw new RuntimeException('Concurrent initialization left invalid account state');
    }
    echo "Concurrent Vault initialization tests passed\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($userIds as $userId) {
        $pdo->prepare('DELETE FROM encrypted_record_sync_state WHERE user_id = :id')->execute([':id' => $userId]);
        $pdo->prepare('DELETE FROM user_financial_vaults WHERE user_id = :id')->execute([':id' => $userId]);
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $userId]);
    }
    foreach (['ready-0', 'ready-1', 'start'] as $file) @unlink($directory . '/' . $file);
    @rmdir($directory);
}
