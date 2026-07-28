<?php

declare(strict_types=1);

if (getenv('PRIVACY_PARITY_TEST') !== '1') { fwrite(STDERR, "Refusing Phase 6 assertion outside parity mode\n"); exit(2); }
$dsn=(string)getenv('DB_DSN');
if (!preg_match('/dbname=[^;]*_privacy_parity_test(?:;|$)/',$dsn)) { fwrite(STDERR, "Refusing non-parity database\n"); exit(2); }
if (($argv[1] ?? '') === '') { fwrite(STDERR, "Usage: php scripts/assert_phase6_cutover_account.php email [post_cleanup]\n"); exit(2); }
require __DIR__.'/../src/bootstrap.php';
$pdo=new PDO($dsn,(string)getenv('DB_USER'),(string)getenv('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$email=(string)$argv[1]; $postCleanup=($argv[2] ?? '') === 'post_cleanup'; $failures=[];
$one=static function(PDO $pdo,string $sql,array $params=[]):mixed{$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();};
$uid=(int)$one($pdo,'SELECT id FROM users WHERE email=:email LIMIT 1',[':email'=>$email]);
if($uid<1)throw new RuntimeException('cutover account not found');
$state=(string)$one($pdo,'SELECT financial_privacy_state FROM users WHERE id=:id',[':id'=>$uid]);
if($state!=='encrypted')$failures[]="privacy state is {$state}";
$migration=(int)$one($pdo,"SELECT COUNT(*) FROM financial_privacy_migrations WHERE user_id=:id AND status='completed'",[':id'=>$uid]);
if($migration!==1)$failures[]="completed migration count is {$migration}";
$encrypted=(int)$one($pdo,'SELECT COUNT(*) FROM encrypted_financial_records WHERE user_id=:id AND is_deleted=0',[':id'=>$uid]);
$changes=(int)$one($pdo,'SELECT COUNT(*) FROM encrypted_record_changes WHERE user_id=:id AND is_deleted=0',[':id'=>$uid]);
if($encrypted<1||$encrypted!==$changes)$failures[]="encrypted records/changes mismatch {$encrypted}/{$changes}";
$cleanup=(string)$one($pdo,"SELECT status FROM financial_privacy_cleanup_jobs WHERE user_id=:id ORDER BY id DESC LIMIT 1",[':id'=>$uid]);
if($postCleanup){if($cleanup!=='completed')$failures[]="cleanup status is {$cleanup}";}elseif(!in_array($cleanup,['pending','running','retry_pending'],true))$failures[]="cleanup was not scheduled: {$cleanup}";
$suffix=preg_replace('/^phase5-browser-|@example\.test$/','',$email);$canary='PHASE5_BROWSER_CANARY_'.$suffix;
$canaryCount=(int)$one($pdo,'SELECT COUNT(*) FROM transactions WHERE user_id=:id AND expense=:canary',[':id'=>$uid,':canary'=>$canary]);
if($postCleanup&&$canaryCount!==0)$failures[]='plaintext canary remains in transactions';
if(!$postCleanup&&$canaryCount!==1)$failures[]="pre-cleanup canary count is {$canaryCount}";
if($postCleanup){foreach(App\Privacy\PrivacyCleanupService::protectedTables() as $table){$exists=(int)$one($pdo,'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table',[':table'=>$table]);if($exists===1){$count=(int)$one($pdo,"SELECT COUNT(*) FROM {$table} WHERE user_id=:id",[':id'=>$uid]);if($count!==0)$failures[]="plaintext rows remain in {$table}: {$count}";}}}
if($failures!==[]){fwrite(STDERR,implode("\n",$failures)."\n");exit(1);}
echo json_encode(['ok'=>true,'privacy_state'=>$state,'encrypted_records'=>$encrypted,'cleanup_status'=>$cleanup,'post_cleanup'=>$postCleanup],JSON_UNESCAPED_SLASHES)."\n";
