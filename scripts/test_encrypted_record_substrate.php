<?php

declare(strict_types=1);

use App\Auth\AuthContext;
use App\Core\Config;
use App\Http\HttpException;
use App\Privacy\EncryptedRecordRepository;
use App\Privacy\EncryptedRecordService;
use App\Privacy\FinancialPrivacyStateService;
use App\Privacy\VaultCryptoProfile;
use App\Privacy\VaultRepository;
use App\Security\AuditLogger;

if (getenv('PRIVACY_PARITY_TEST') !== '1') { fwrite(STDERR, "Refusing encrypted-record test: set PRIVACY_PARITY_TEST=1\n"); exit(2); }
$dsn = (string) getenv('DB_DSN');
if (!preg_match('/^mysql:.*dbname=([^;]+)/', $dsn, $match) || !str_ends_with($match[1], '_privacy_parity_test')) { fwrite(STDERR, "DB_DSN must point to the dedicated *_privacy_parity_test database\n"); exit(2); }
require __DIR__ . '/../src/bootstrap.php';
$pdo = new PDO($dsn, (string) getenv('DB_USER'), (string) getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$suffix = bin2hex(random_bytes(6));
$email = 'phase3-record-' . $suffix . '@example.test';
$stmt = $pdo->prepare("INSERT INTO users (email, display_name, auth_provider, password_hash, email_verified, role, is_active, financial_privacy_state) VALUES (:email, 'Phase 3 Record Test', 'password', :password_hash, 1, 'member', 1, 'vault_setup_required')");
$stmt->execute([':email' => $email, ':password_hash' => password_hash('phase3-test-only', PASSWORD_DEFAULT)]);
$userId = (int) $pdo->lastInsertId();

function b64(string $bytes): string { return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); }
function expectError(callable $fn, int $status, string $code): void { try { $fn(); } catch (HttpException $e) { if ($e->status !== $status || $e->errorCode !== $code) throw new RuntimeException("expected {$status}/{$code}, got {$e->status}/{$e->errorCode}"); return; } throw new RuntimeException("expected {$status}/{$code}"); }

try {
    $vaults = new VaultRepository($pdo);
    $vaults->create($userId, 'vault_' . $suffix, [
        'crypto_profile_version' => 1, 'passphrase_kdf' => 'PBKDF2', 'passphrase_kdf_hash' => 'SHA-256', 'passphrase_kdf_iterations' => VaultCryptoProfile::KDF_ITERATIONS,
        'passphrase_wrap_algorithm' => 'AES-KW', 'passphrase_kdf_salt' => str_repeat('s', 32), 'passphrase_wrapped_vault_key' => str_repeat('p', 40),
        'recovery_wrap_algorithm' => 'AES-KW', 'recovery_wrapped_vault_key' => str_repeat('r', 40),
    ]);
    $service = new EncryptedRecordService($pdo, new EncryptedRecordRepository($pdo), $vaults, new FinancialPrivacyStateService($pdo), new AuditLogger($pdo));
    $auth = new AuthContext(['id' => $userId, 'role' => 'member'], 'session', 'phase3-session-' . $suffix);
    $request = new App\Http\Request('POST', '/api/v1/me/encrypted-records', '', [], [], [], [], ['User-Agent' => 'phase3-record-test']);
    $recordId = 'rec_' . str_repeat('a', 18);
    $canary = 'PHASE3_PLAINTEXT_CANARY_' . $suffix;
    $envelope = ['vault_id' => 'vault_' . $suffix, 'record_id' => $recordId, 'envelope_version' => 1, 'record_revision' => 1, 'iv' => b64(str_repeat('i', 12)), 'ciphertext' => b64(random_bytes(48)), 'sync_sequence' => '0', 'deleted' => false];
    $created = $service->create($auth, ['envelope' => $envelope, 'idempotency_key' => 'mut_' . str_repeat('a', 18)], $request);
    if ($created['record_revision'] !== 1 || $created['deleted'] || $created['sync_sequence'] !== '1') throw new RuntimeException('create result incorrect');
    $retry = $service->create($auth, ['envelope' => $envelope, 'idempotency_key' => 'mut_' . str_repeat('a', 18)]);
    if (($retry['idempotent'] ?? false) !== true) throw new RuntimeException('create retry was not idempotent');
    $changes = $service->sync($auth, '0', 10);
    if (count($changes['changes']) !== 1 || $changes['next_cursor'] !== '1') throw new RuntimeException('initial sync incorrect');
    $next = $envelope; $next['record_revision'] = 2; $next['ciphertext'] = b64(random_bytes(52));
    $updated = $service->update($auth, $recordId, ['expected_revision' => 1, 'envelope' => $next, 'idempotency_key' => 'mut_' . str_repeat('b', 18)], $request);
    if ($updated['record_revision'] !== 2 || $updated['sync_sequence'] !== '2') throw new RuntimeException('update result incorrect');
    expectError(fn() => $service->update($auth, $recordId, ['expected_revision' => 1, 'envelope' => $next, 'idempotency_key' => 'mut_' . str_repeat('c', 18)]), 409, 'ENCRYPTED_RECORD_REVISION_CONFLICT');
    expectError(fn() => $service->update($auth, $recordId, ['expected_revision' => 2, 'envelope' => $next, 'idempotency_key' => 'mut_' . str_repeat('d', 18)]), 422, 'ENCRYPTED_RECORD_PAYLOAD_INVALID');
    $deleted = $service->delete($auth, $recordId, ['expected_revision' => 2, 'idempotency_key' => 'mut_' . str_repeat('e', 18)], $request);
    if (!$deleted['deleted'] || $deleted['record_revision'] !== 3 || $deleted['sync_sequence'] !== '3' || isset($deleted['ciphertext'])) throw new RuntimeException('tombstone result incorrect');
    expectError(fn() => $service->create($auth, ['envelope' => $envelope, 'idempotency_key' => 'mut_' . str_repeat('f', 18)]), 409, 'ENCRYPTED_RECORD_TOMBSTONED');
    expectError(fn() => $service->delete($auth, $recordId, ['expected_revision' => 2, 'idempotency_key' => 'mut_' . str_repeat('g', 18)]), 409, 'ENCRYPTED_RECORD_TOMBSTONED');
    expectError(fn() => $service->sync($auth, '99', 10), 422, 'ENCRYPTED_SYNC_CURSOR_INVALID');
    $all = $service->sync($auth, '0', 2);
    if (count($all['changes']) !== 2 || !$all['has_more'] || $all['next_cursor'] !== '2') throw new RuntimeException('sync pagination incorrect');
    $rest = $service->sync($auth, $all['next_cursor'], 2);
    if (count($rest['changes']) !== 1 || !$rest['changes'][0]['deleted'] || $rest['next_cursor'] !== '3') throw new RuntimeException('tombstone sync incorrect');
    $row = $pdo->prepare('SELECT vault_id, record_id, envelope_version, record_revision, iv, ciphertext, sync_sequence, is_deleted FROM encrypted_financial_records WHERE user_id = :user_id AND record_id = :record_id');
    $row->execute([':user_id' => $userId, ':record_id' => $recordId]); $stored = $row->fetch();
    if (!is_array($stored) || (int) $stored['is_deleted'] !== 1 || $stored['ciphertext'] !== null || str_contains(json_encode($stored), $canary)) throw new RuntimeException('stored tombstone or canary check failed');
    $audit = $pdo->prepare("SELECT metadata FROM audit_logs WHERE actor_user_id = :user_id AND target_id = :record_id"); $audit->execute([':user_id' => $userId, ':record_id' => $recordId]);
    foreach ($audit->fetchAll(PDO::FETCH_COLUMN) as $metadata) if (str_contains((string) $metadata, $canary) || str_contains((string) $metadata, 'ciphertext')) throw new RuntimeException('unsafe encrypted-record audit metadata');
    $columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'encrypted_financial_records'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['plaintext', 'amount', 'name', 'date', 'passphrase', 'recovery_secret', 'vault_key'] as $forbidden) if (in_array($forbidden, $columns, true)) throw new RuntimeException("forbidden encrypted-record column exists: {$forbidden}");
    $state = $pdo->prepare('SELECT financial_privacy_state FROM users WHERE id = :id'); $state->execute([':id' => $userId]); $stateRow = $state->fetch();
    if ($stateRow['financial_privacy_state'] !== 'vault_setup_required') throw new RuntimeException('encrypted substrate changed setup state');
    echo "Encrypted record substrate tests passed\n";
} finally {
    $pdo->prepare('DELETE FROM audit_logs WHERE actor_user_id = :id')->execute([':id' => $userId]);
    $pdo->prepare('DELETE FROM encrypted_record_changes WHERE user_id = :id')->execute([':id' => $userId]);
    $pdo->prepare('DELETE FROM encrypted_financial_records WHERE user_id = :id')->execute([':id' => $userId]);
    $pdo->prepare('DELETE FROM encrypted_record_sync_state WHERE user_id = :id')->execute([':id' => $userId]);
    $pdo->prepare('DELETE FROM user_financial_vaults WHERE user_id = :id')->execute([':id' => $userId]);
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $userId]);
}
