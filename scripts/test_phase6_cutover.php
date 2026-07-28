<?php

declare(strict_types=1);

use App\Auth\AuthService;
use App\Controllers\PrivacyController;
use App\Core\Config;
use App\Http\Request;
use App\Http\HttpException;
use App\Privacy\FinancialPrivacyState;
use App\Privacy\FinancialPrivacyStateService;
use App\Privacy\FinancialRevisionService;
use App\Privacy\MigrationSnapshotService;
use App\Privacy\MigrationStagingRepository;
use App\Privacy\PrivacyCleanupRepository;
use App\Privacy\PrivacyCleanupService;
use App\Privacy\PrivacyCutoverService;
use App\Privacy\PrivacyMigrationRepository;
use App\Privacy\PrivacyMigrationService;
use App\Privacy\RecentAuthGuard;
use App\Privacy\VaultRepository;
use App\Support\Str;

if (getenv('PRIVACY_PARITY_TEST') !== '1') { fwrite(STDERR, "Refusing Phase 6 test outside parity mode\n"); exit(2); }
$dsn=(string)getenv('DB_DSN'); if(!preg_match('/dbname=[^;]*_privacy_parity_test(?:;|$)/',$dsn)){fwrite(STDERR,"DB_DSN must point to parity database\n");exit(2);}
require __DIR__.'/../src/bootstrap.php';
$pdo=new PDO($dsn,(string)getenv('DB_USER'),(string)getenv('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$suffix=bin2hex(random_bytes(5));$email="phase6-{$suffix}@example.test";$secret='phase6-session-secret';$session="phase6_{$suffix}";
$pdo->prepare("INSERT INTO users (email,display_name,auth_provider,password_hash,email_verified,role,is_active,financial_privacy_state) VALUES (:email,'Phase 6 Test','password',:password,1,'member',1,'legacy_plaintext')")->execute([':email'=>$email,':password'=>password_hash('phase6-test',PASSWORD_DEFAULT)]);$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO user_sessions (session_id,user_id,session_secret_hash,client_type,created_at,expires_at) VALUES (:sid,:uid,:hash,'native',:created_at,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))")->execute([':sid'=>$session,':uid'=>$uid,':hash'=>Str::hashSha256($secret),':created_at'=>gmdate('Y-m-d H:i:s')]);
$pdo->prepare("INSERT INTO user_financial_vaults (vault_id,user_id,crypto_profile_version,passphrase_kdf,passphrase_kdf_hash,passphrase_kdf_iterations,passphrase_wrap_algorithm,passphrase_kdf_salt,passphrase_wrapped_vault_key,recovery_wrap_algorithm,recovery_wrapped_vault_key) VALUES (:vault,:uid,1,'PBKDF2','SHA-256',600000,'AES-KW',:salt,:wrapped,'AES-KW',:recovery)")->execute([':vault'=>"vault_phase6_{$suffix}",':uid'=>$uid,':salt'=>random_bytes(16),':wrapped'=>random_bytes(32),':recovery'=>random_bytes(32)]);
$headers=['Authorization'=>"Session {$session}.{$secret}"];$request=static fn(string $method,string $body='{}'):Request=>new Request($method,'',$body,[],[],[],[],$headers);
try{
 $config=Config::load(dirname(__DIR__));$states=new FinancialPrivacyStateService($pdo);$revisions=new FinancialRevisionService($pdo);$migrations=new PrivacyMigrationRepository($pdo);$cleanup=new PrivacyCleanupRepository($pdo);$staging=new MigrationStagingRepository($pdo);$vaults=new VaultRepository($pdo);$migrationService=new PrivacyMigrationService($pdo,$states,$revisions,$migrations);$controller=new PrivacyController(new AuthService($pdo,$config),$states,$revisions,$migrations,$cleanup,$migrationService,new MigrationSnapshotService($pdo,$revisions,$migrations),$staging,$vaults,new RecentAuthGuard($config),new PrivacyCutoverService($pdo,$states,$revisions,$migrations,$staging,$vaults,$cleanup));
 $start=$controller->start($request('POST'));$first=json_decode($start->body,true)['migration'];$firstId=$first['migration_id'];
 $target=['target_record_id'=>'mig:synthetic:phase6','record_family'=>'synthetic','record_schema_version'=>'synthetic_v1'];$manifest=['manifest_version'=>'phase5_target_manifest_v1','snapshot_schema_version'=>MigrationSnapshotService::SNAPSHOT_SCHEMA_VERSION,'source_financial_revision'=>(int)$first['source_financial_revision'],'relationship_count'=>0,'targets'=>[$target]];
 $controller->putManifest($request('PUT',json_encode($manifest)),['migration_id'=>$firstId]);$record=['target_record_id'=>$target['target_record_id'],'record_family'=>'synthetic','record_schema_version'=>'synthetic_v1','envelope_version'=>1,'iv'=>rtrim(strtr(base64_encode(random_bytes(12)),'+/','-_'),'='),'ciphertext'=>rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=')];$controller->putRecord($request('PUT',json_encode($record)),['migration_id'=>$firstId,'record_id'=>$target['target_record_id']]);$controller->verify($request('POST'),['migration_id'=>$firstId]);
 $revisions->increment($uid);$stale=false;try{$controller->cutover($request('POST'),['migration_id'=>$firstId]);}catch(HttpException $e){$stale=$e->errorCode==='MIGRATION_SOURCE_REVISION_CHANGED';}if(!$stale||$states->get($uid)!==FinancialPrivacyState::MIGRATION_IN_PROGRESS)throw new RuntimeException('pre-commit stale revision safety failed');
 $controller->cancel($request('POST'),['migration_id'=>$firstId]);$start=$controller->start($request('POST'));$second=json_decode($start->body,true)['migration'];$secondId=$second['migration_id'];$manifest['source_financial_revision']=(int)$second['source_financial_revision'];$controller->putManifest($request('PUT',json_encode($manifest)),['migration_id'=>$secondId]);$controller->putRecord($request('PUT',json_encode($record)),['migration_id'=>$secondId,'record_id'=>$target['target_record_id']]);$controller->verify($request('POST'),['migration_id'=>$secondId]);
 $cut=$controller->cutover($request('POST'),['migration_id'=>$secondId]);if($cut->status!==200||$states->get($uid)!==FinancialPrivacyState::ENCRYPTED)throw new RuntimeException('cutover did not establish encrypted authority');$again=json_decode($controller->cutover($request('POST'),['migration_id'=>$secondId])->body,true);if(($again['idempotent']??false)!==true)throw new RuntimeException('cutover idempotency failed');if($cleanup->getForMigration($uid,$secondId)===null)throw new RuntimeException('cleanup was not scheduled');
 $cleanupService=new PrivacyCleanupService($pdo,$cleanup);$job=$cleanup->getForMigration($uid,$secondId);for($attempt=0;$attempt<20 && is_array($job) && $job['status']!=='completed';$attempt++){ $cleanupService->runNext(); $job=$cleanup->getForMigration($uid,$secondId); }if(!is_array($job)||$job['status']!=='completed'||$states->get($uid)!==FinancialPrivacyState::ENCRYPTED)throw new RuntimeException('cleanup changed authority or did not complete: '.json_encode(['job'=>$job,'state'=>$states->get($uid)]));
 echo "Phase 6 cutover tests passed\n";
}finally{
 $pdo->prepare('DELETE FROM encrypted_record_changes WHERE user_id=:uid')->execute([':uid'=>$uid]);$pdo->prepare('DELETE FROM encrypted_financial_records WHERE user_id=:uid')->execute([':uid'=>$uid]);$pdo->prepare('DELETE FROM encrypted_record_sync_state WHERE user_id=:uid')->execute([':uid'=>$uid]);$pdo->prepare('DELETE FROM financial_privacy_cleanup_jobs WHERE user_id=:uid')->execute([':uid'=>$uid]);$pdo->prepare('DELETE FROM encrypted_migration_records WHERE user_id=:uid')->execute([':uid'=>$uid]);$pdo->prepare('DELETE FROM encrypted_migration_manifests WHERE user_id=:uid')->execute([':uid'=>$uid]);$pdo->prepare('DELETE FROM financial_privacy_migrations WHERE user_id=:uid')->execute([':uid'=>$uid]);$pdo->prepare('DELETE FROM user_financial_vaults WHERE user_id=:uid')->execute([':uid'=>$uid]);$pdo->prepare('DELETE FROM user_sessions WHERE user_id=:uid')->execute([':uid'=>$uid]);$pdo->prepare('DELETE FROM users WHERE id=:uid')->execute([':uid'=>$uid]);
}
