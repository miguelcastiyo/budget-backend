<?php

declare(strict_types=1);

use App\Auth\AuthService;
use App\Core\Config;
use App\Http\Request;
use App\Privacy\FinancialPrivacyState;
use App\Privacy\FinancialPrivacyStateService;
use App\Privacy\FinancialRevisionService;
use App\Privacy\MigrationSnapshotService;
use App\Privacy\MigrationStagingRepository;
use App\Privacy\PrivacyCleanupRepository;
use App\Privacy\PrivacyMigrationRepository;
use App\Privacy\PrivacyMigrationService;
use App\Privacy\RecentAuthGuard;
use App\Privacy\VaultRepository;
use App\Controllers\PrivacyController;
use App\Support\Str;

if (getenv('PRIVACY_PARITY_TEST') !== '1') { fwrite(STDERR, "Refusing migration staging test: set PRIVACY_PARITY_TEST=1\n"); exit(2); }
$dsn = getenv('DB_DSN') ?: '';
if (!preg_match('/dbname=[^;]*_privacy_parity_test(?:;|$)/', $dsn)) { fwrite(STDERR, "DB_DSN must point to the dedicated parity database\n"); exit(2); }
require __DIR__ . '/../src/bootstrap.php';
$pdo = new PDO($dsn, (string) getenv('DB_USER'), (string) getenv('DB_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$suffix = bin2hex(random_bytes(5));
$email = "phase5-{$suffix}@example.test";
$pdo->prepare("INSERT INTO users (email,display_name,auth_provider,password_hash,email_verified,role,is_active,financial_privacy_state) VALUES (:email,'Phase 5 Test','password',:password,1,'member',1,'legacy_plaintext')")->execute([':email'=>$email,':password'=>password_hash('phase5-test',PASSWORD_DEFAULT)]);
$userId = (int) $pdo->lastInsertId();
$sessionId = "phase5_{$suffix}"; $secret = 'phase5-session-secret';
$pdo->prepare("INSERT INTO user_sessions (session_id,user_id,session_secret_hash,client_type,created_at,expires_at) VALUES (:sid,:uid,:hash,'native',:created_at,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))")->execute([':sid'=>$sessionId,':uid'=>$userId,':hash'=>Str::hashSha256($secret),':created_at'=>gmdate('Y-m-d H:i:s')]);
$pdo->prepare("INSERT INTO user_financial_vaults (vault_id,user_id,crypto_profile_version,passphrase_kdf,passphrase_kdf_hash,passphrase_kdf_iterations,passphrase_wrap_algorithm,passphrase_kdf_salt,passphrase_wrapped_vault_key,recovery_wrap_algorithm,recovery_wrapped_vault_key) VALUES ('vault_phase5',:uid,1,'PBKDF2','SHA-256',600000,'AES-KW',:salt,:wrapped,'AES-KW',:recovery)")->execute([':uid'=>$userId,':salt'=>random_bytes(16),':wrapped'=>random_bytes(32),':recovery'=>random_bytes(32)]);

try {
    $config = Config::load(dirname(__DIR__));
    $states = new FinancialPrivacyStateService($pdo); $revisions = new FinancialRevisionService($pdo);
    $migrations = new PrivacyMigrationRepository($pdo); $cleanup = new PrivacyCleanupRepository($pdo);
    $migrationService = new PrivacyMigrationService($pdo,$states,$revisions,$migrations);
    $staging = new MigrationStagingRepository($pdo);
    $controller = new PrivacyController(new AuthService($pdo,$config),$states,$revisions,$migrations,$cleanup,$migrationService,new MigrationSnapshotService($pdo,$revisions,$migrations),$staging,new VaultRepository($pdo),new RecentAuthGuard($config));
    $headers = ['Authorization'=>"Session {$sessionId}.{$secret}"];
    $start = $controller->start(new Request('POST','/me/privacy/migration','{}',[],[],[],[],$headers));
    $migration = json_decode($start->body,true)['migration'] ?? null;
    if ($start->status !== 201 || !is_array($migration) || $states->get($userId) !== FinancialPrivacyState::MIGRATION_IN_PROGRESS) throw new RuntimeException('migration start failed');
    $migrationId = (string) $migration['migration_id'];
    $snapshot = $controller->snapshot(new Request('GET','/me/privacy/migration/'.$migrationId.'/snapshot','',[],[],[],[],$headers),['migration_id'=>$migrationId]);
    $snapshotBody = json_decode($snapshot->body,true);
    if ($snapshot->status !== 200 || $snapshot->headers['Cache-Control'] !== 'no-store, private' || !isset($snapshotBody['source_manifest'],$snapshotBody['collections'])) throw new RuntimeException('snapshot contract failed');
    $target = ['target_record_id'=>'mig:synthetic:1','record_family'=>'synthetic','record_schema_version'=>'synthetic_v1'];
    $controller->putManifest(new Request('PUT','',json_encode(['manifest_version'=>'phase5_target_manifest_v1','snapshot_schema_version'=>MigrationSnapshotService::SNAPSHOT_SCHEMA_VERSION,'source_financial_revision'=>(int)$migration['source_financial_revision'],'relationship_count'=>0,'targets'=>[$target]],JSON_THROW_ON_ERROR),[],[],[],[],$headers),['migration_id'=>$migrationId]);
    $record = ['target_record_id'=>$target['target_record_id'],'record_family'=>'synthetic','record_schema_version'=>'synthetic_v1','envelope_version'=>1,'iv'=>rtrim(strtr(base64_encode(random_bytes(12)),'+/','-_'),'='),'ciphertext'=>rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=')];
    $controller->putRecord(new Request('PUT','',json_encode($record,JSON_THROW_ON_ERROR),[],[],[],[],$headers),['migration_id'=>$migrationId,'record_id'=>$target['target_record_id']]);
    $controller->putRecord(new Request('PUT','',json_encode($record,JSON_THROW_ON_ERROR),[],[],[],[],$headers),['migration_id'=>$migrationId,'record_id'=>$target['target_record_id']]);
    $verify = $controller->verify(new Request('POST','{}','{}',[],[],[],[],$headers),['migration_id'=>$migrationId]);
    $verified = json_decode($verify->body,true)['status'] ?? [];
    if ($verify->status !== 200 || $verified['staged_count'] !== 1 || !$verified['verified']) throw new RuntimeException('staging verification/idempotency failed');
    $cancel = $controller->cancel(new Request('POST','{}','{}',[],[],[],[],$headers),['migration_id'=>$migrationId]);
    if ($cancel->status !== 200 || $states->get($userId) !== FinancialPrivacyState::LEGACY_PLAINTEXT) throw new RuntimeException('cancel safety failed');
    $second = json_decode($controller->start(new Request('POST','{}','{}',[],[],[],[],$headers))->body,true)['migration'];
    $secondId = (string) $second['migration_id'];
    $revisions->increment($userId);
    $staleRejected = false;
    try { $controller->snapshot(new Request('GET','', '',[],[],[],[],$headers),['migration_id'=>$secondId]); }
    catch (\App\Http\HttpException $e) { $staleRejected = $e->errorCode === 'MIGRATION_SOURCE_REVISION_CHANGED'; }
    if (!$staleRejected) throw new RuntimeException('stale revision was accepted');
    $controller->cancel(new Request('POST','{}','{}',[],[],[],[],$headers),['migration_id'=>$secondId]);
    echo "Phase 5 migration staging tests passed\n";
} finally {
    $pdo->prepare('DELETE FROM encrypted_migration_records WHERE user_id=:uid')->execute([':uid'=>$userId]);
    $pdo->prepare('DELETE FROM encrypted_migration_manifests WHERE user_id=:uid')->execute([':uid'=>$userId]);
    $pdo->prepare('DELETE FROM financial_privacy_migrations WHERE user_id=:uid')->execute([':uid'=>$userId]);
    $pdo->prepare('DELETE FROM user_financial_vaults WHERE user_id=:uid')->execute([':uid'=>$userId]);
    $pdo->prepare('DELETE FROM user_sessions WHERE user_id=:uid')->execute([':uid'=>$userId]);
    $pdo->prepare('DELETE FROM users WHERE id=:uid')->execute([':uid'=>$userId]);
}
