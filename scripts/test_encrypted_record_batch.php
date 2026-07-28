<?php

declare(strict_types=1);

use App\Auth\AuthContext;
use App\Http\HttpException;
use App\Privacy\EncryptedRecordRepository;
use App\Privacy\EncryptedRecordService;
use App\Privacy\FinancialPrivacyStateService;
use App\Privacy\VaultCryptoProfile;
use App\Privacy\VaultRepository;
use App\Security\AuditLogger;

if (getenv('PRIVACY_PARITY_TEST') !== '1') { fwrite(STDERR, "Refusing encrypted batch test: set PRIVACY_PARITY_TEST=1\n"); exit(2); }
$dsn = (string) getenv('DB_DSN');
if (!preg_match('/^mysql:.*dbname=([^;]+)/', $dsn, $match) || !str_ends_with($match[1], '_privacy_parity_test')) { fwrite(STDERR, "DB_DSN must point to the dedicated *_privacy_parity_test database\n"); exit(2); }
require __DIR__ . '/../src/bootstrap.php';
$pdo = new PDO($dsn, (string) getenv('DB_USER'), (string) getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$suffix = bin2hex(random_bytes(6));
$pdo->prepare("INSERT INTO users (email,display_name,auth_provider,password_hash,email_verified,role,is_active,financial_privacy_state) VALUES (:email,'Phase 6B Batch Test','password',:password,1,'member',1,'legacy_plaintext')")->execute([':email'=>"phase6b-batch-{$suffix}@example.test", ':password'=>password_hash('phase6b-test', PASSWORD_DEFAULT)]);
$userId = (int) $pdo->lastInsertId();
function batchB64(string $bytes): string { return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); }
function batchEnvelope(string $vault, string $id, int $revision, ?string $ciphertext = null): array { return ['vault_id'=>$vault,'record_id'=>$id,'envelope_version'=>1,'record_revision'=>$revision,'iv'=>batchB64(str_repeat('i',12)),'ciphertext'=>batchB64($ciphertext ?? random_bytes(40)),'sync_sequence'=>'0','deleted'=>false]; }
function batchError(callable $fn, string $code): void { try { $fn(); } catch (HttpException $e) { if ($e->errorCode !== $code) throw new RuntimeException("expected {$code}, got {$e->errorCode}"); return; } throw new RuntimeException("expected {$code}"); }
try {
    $vaultId = "vault_batch_{$suffix}"; $vaults = new VaultRepository($pdo);
    $vaults->create($userId, $vaultId, ['crypto_profile_version'=>1,'passphrase_kdf'=>'PBKDF2','passphrase_kdf_hash'=>'SHA-256','passphrase_kdf_iterations'=>VaultCryptoProfile::KDF_ITERATIONS,'passphrase_wrap_algorithm'=>'AES-KW','passphrase_kdf_salt'=>str_repeat('s',32),'passphrase_wrapped_vault_key'=>str_repeat('p',40),'recovery_wrap_algorithm'=>'AES-KW','recovery_wrapped_vault_key'=>str_repeat('r',40)]);
    $service = new EncryptedRecordService($pdo, new EncryptedRecordRepository($pdo), $vaults, new FinancialPrivacyStateService($pdo), new AuditLogger($pdo));
    $auth = new AuthContext(['id'=>$userId,'role'=>'member'], 'session', "batch-session-{$suffix}");
    $a = 'rec_' . str_repeat('a', 18); $b = 'rec_' . str_repeat('b', 18); $batchKey = 'mut_' . str_repeat('k', 18);
    $created = $service->batch($auth, ['idempotency_key'=>$batchKey,'creates'=>[['envelope'=>batchEnvelope($vaultId,$a,1),'idempotency_key'=>'mut_'.str_repeat('1',18)],['envelope'=>batchEnvelope($vaultId,$b,1),'idempotency_key'=>'mut_'.str_repeat('2',18)]],'updates'=>[],'tombstones'=>[]]);
    if (count($created['records']) !== 2 || $created['records'][0]['sync_sequence'] !== '1' || $created['records'][1]['sync_sequence'] !== '2') throw new RuntimeException('batch create sequence failed');
    $retry = $service->batch($auth, ['idempotency_key'=>$batchKey,'creates'=>[],'updates'=>[],'tombstones'=>[]]);
    if (($retry['idempotent'] ?? false) !== true || count($retry['records']) !== 2) throw new RuntimeException('batch retry failed');
    $valid = batchEnvelope($vaultId,$a,2); $stale = batchEnvelope($vaultId,$b,2);
    batchError(fn() => $service->batch($auth, ['idempotency_key'=>'mut_'.str_repeat('c',18),'creates'=>[],'updates'=>[['envelope'=>$valid,'expected_revision'=>1,'idempotency_key'=>'mut_'.str_repeat('3',18)],['envelope'=>$stale,'expected_revision'=>0,'idempotency_key'=>'mut_'.str_repeat('4',18)]],'tombstones'=>[]]), 'ENCRYPTED_RECORD_PAYLOAD_INVALID');
    $rows = $pdo->prepare('SELECT record_id,record_revision,sync_sequence FROM encrypted_financial_records WHERE user_id=:user_id ORDER BY record_id'); $rows->execute([':user_id'=>$userId]);
    foreach ($rows->fetchAll() as $row) if ((int)$row['record_revision'] !== 1 || (int)$row['sync_sequence'] > 2) throw new RuntimeException('failed batch partially persisted');
    $all = $service->sync($auth, '0', 10); if (count($all['changes']) !== 2 || $all['next_cursor'] !== '2') throw new RuntimeException('batch sync sequence integrity failed');
    echo "Encrypted record batch tests passed\n";
} finally {
    $pdo->prepare('DELETE FROM encrypted_record_batches WHERE user_id=:id')->execute([':id'=>$userId]);
    $pdo->prepare('DELETE FROM encrypted_record_changes WHERE user_id=:id')->execute([':id'=>$userId]);
    $pdo->prepare('DELETE FROM encrypted_financial_records WHERE user_id=:id')->execute([':id'=>$userId]);
    $pdo->prepare('DELETE FROM encrypted_record_sync_state WHERE user_id=:id')->execute([':id'=>$userId]);
    $pdo->prepare('DELETE FROM user_financial_vaults WHERE user_id=:id')->execute([':id'=>$userId]);
    $pdo->prepare('DELETE FROM users WHERE id=:id')->execute([':id'=>$userId]);
}
