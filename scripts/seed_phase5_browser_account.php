<?php

declare(strict_types=1);

if (getenv('PRIVACY_PARITY_TEST') !== '1') { fwrite(STDERR, "Refusing Phase 5 browser seed: set PRIVACY_PARITY_TEST=1\n"); exit(2); }
$dsn = (string) getenv('DB_DSN');
if (!preg_match('/dbname=[^;]*_privacy_parity_test(?:;|$)/', $dsn)) { fwrite(STDERR, "Refusing non-parity database\n"); exit(2); }
require __DIR__ . '/../src/bootstrap.php';
$pdo = new PDO($dsn, (string)getenv('DB_USER'), (string)getenv('DB_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$suffix = preg_replace('/[^a-z0-9]/i', '', (string)(getenv('PHASE5_BROWSER_SUFFIX') ?: bin2hex(random_bytes(5))));
$email = "phase5-browser-{$suffix}@example.test";
$password = 'phase5-browser-passphrase';
$pdo->beginTransaction();
try {
    $old = $pdo->prepare('SELECT id FROM users WHERE email = :email');
    $old->execute([':email'=>$email]);
    foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $oldUserId) {
        $pdo->prepare('DELETE FROM audit_logs WHERE actor_user_id=:uid')->execute([':uid'=>(int)$oldUserId]);
        foreach (['encrypted_record_batches','encrypted_migration_records','encrypted_migration_manifests','financial_privacy_cleanup_jobs','financial_privacy_migrations','encrypted_record_changes','encrypted_financial_records','encrypted_record_sync_state','user_financial_vaults','user_sessions','fund_entries','monthly_savings_allocations','monthly_closeout_allocations','monthly_closeouts','recurring_expense_occurrences','transactions','recurring_expenses','budget_settings_versions','budget_settings','csv_import_runs','funds','contexts','cards','tags'] as $table) {
            $pdo->prepare("DELETE FROM {$table} WHERE user_id=:uid")->execute([':uid'=>(int)$oldUserId]);
        }
        $pdo->prepare('DELETE FROM users WHERE id=:uid')->execute([':uid'=>(int)$oldUserId]);
    }
    $pdo->prepare("INSERT INTO users (email,display_name,auth_provider,password_hash,email_verified,role,is_active,financial_privacy_state) VALUES (:email,'Phase 5 Browser Synthetic','password',:password,1,'member',1,'legacy_plaintext')")->execute([':email'=>$email,':password'=>password_hash($password,PASSWORD_DEFAULT)]);
    $userId=(int)$pdo->lastInsertId();
    $sessionId = "phase5seed_{$suffix}";
    $sessionSecret = "phase5-seed-secret-{$suffix}";
    $csrfToken = "phase5-csrf-{$suffix}";
    $pdo->prepare("INSERT INTO user_sessions (session_id,user_id,session_secret_hash,csrf_token_hash,client_type,expires_at) VALUES (:sid,:uid,:secret,:csrf,'web',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))")->execute([':sid'=>$sessionId,':uid'=>$userId,':secret'=>hash('sha256',$sessionSecret),':csrf'=>hash('sha256',$csrfToken)]);
    $pdo->prepare("INSERT INTO tags (user_id,name,icon_key,is_active) VALUES (:uid,'Browser Canary','box',0)")->execute([':uid'=>$userId]); $tagId=(int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE tags SET deleted_at='2026-01-15 00:00:00' WHERE id=:id")->execute([':id'=>$tagId]);
    $pdo->prepare("INSERT INTO tags (user_id,name,icon_key,is_active) VALUES (:uid,'Browser Active','box',1)")->execute([':uid'=>$userId]); $activeTagId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO cards (user_id,name,is_favorite) VALUES (:uid,'Synthetic Card',1)")->execute([':uid'=>$userId]); $cardId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO contexts (user_id,name,icon_key) VALUES (:uid,'Synthetic Context','box')")->execute([':uid'=>$userId]); $contextId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO funds (fund_id,user_id,name,fund_type,goal_amount,target_month,status,sort_order) VALUES (:fund_id,:uid,'Active Synthetic Fund','goal',500.00,'2026-12-01','active',1)")->execute([':fund_id'=>"phase5-{$suffix}-fund-a",':uid'=>$userId]); $fundId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO funds (fund_id,user_id,name,fund_type,goal_amount,target_month,status,sort_order,archived_at) VALUES (:fund_id,:uid,'Completed Synthetic Fund','goal',100.00,'2026-02-01','archived',2,'2026-03-01 00:00:00')")->execute([':fund_id'=>"phase5-{$suffix}-fund-b",':uid'=>$userId]);
    $budget=[':uid'=>$userId,':income'=>'500.00',':pm'=>'500.00'];
    $pdo->prepare("INSERT INTO budget_settings (user_id,monthly_income,income_source_type,primary_monthly_income,side_income_type,allocation_mode,needs_percent,wants_percent,savings_percent) VALUES (:uid,:income,'monthly',:pm,'none','percent',50,30,20)")->execute($budget);
    $pdo->prepare("INSERT INTO budget_settings_versions (user_id,effective_month,monthly_income,income_source_type,primary_monthly_income,side_income_type,allocation_mode,needs_percent,wants_percent,savings_percent) VALUES (:uid,'2026-01-01',:income,'monthly',:pm,'none','percent',50,30,20)")->execute($budget);
    $pdo->prepare("INSERT INTO budget_settings_versions (user_id,effective_month,monthly_income,income_source_type,primary_monthly_income,side_income_type,allocation_mode,needs_amount,wants_amount,savings_amount) VALUES (:uid,'2026-03-01','600.00','monthly','600.00','none','amount','300.00','180.00','120.00')")->execute([':uid'=>$userId]);
    $pdo->prepare("INSERT INTO recurring_expenses (series_id,user_id,expense,amount,category,tag_id,card_id,billing_type,billing_day,starts_month,ends_month,is_active) VALUES (:series,:uid,'Recurring Canary','12.00','needs',:tag,:card,'day_of_month',31,'2026-01-01','2026-02-01',1)")->execute([':series'=>"phase5-{$suffix}-series",':uid'=>$userId,':tag'=>$tagId,':card'=>$cardId]);
    $pdo->prepare("INSERT INTO recurring_expenses (series_id,user_id,expense,amount,category,tag_id,card_id,billing_type,billing_day,starts_month,ends_month,is_active) VALUES (:series,:uid,'Recurring Canary','15.00','needs',:tag,:card,'last_day',NULL,'2026-03-01',NULL,1)")->execute([':series'=>"phase5-{$suffix}-series",':uid'=>$userId,':tag'=>$tagId,':card'=>$cardId]);
    $pdo->prepare("INSERT INTO transactions (user_id,transaction_date,expense,amount,category,tag_id,context_id,card_id,is_split,notes,source) VALUES (:uid,'2026-01-15','PHASE5_BROWSER_CANARY_{$suffix}','25.00','needs',:tag,:context,:card,0,'synthetic migration canary','manual')")->execute([':uid'=>$userId,':tag'=>$tagId,':context'=>$contextId,':card'=>$cardId]); $transactionId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO csv_import_runs (user_id,mode,status,source_filename,total_rows,valid_rows,imported_rows) VALUES (:uid,'commit','completed','phase5.csv',1,1,1)")->execute([':uid'=>$userId]); $importId=(int)$pdo->lastInsertId();
    $fingerprint=hash('sha256','2026-01-20|10.00|imported canary|wants|0|'.$tagId.'|'.$cardId);
    $pdo->prepare("INSERT INTO transactions (user_id,transaction_date,expense,amount,category,tag_id,card_id,is_split,source,import_fingerprint,csv_import_run_id) VALUES (:uid,'2026-01-20','Imported Canary','10.00','wants',:tag,:card,0,'import',:fingerprint,:import)")->execute([':uid'=>$userId,':tag'=>$tagId,':card'=>$cardId,':fingerprint'=>$fingerprint,':import'=>$importId]);
    $pdo->prepare("INSERT INTO recurring_expense_occurrences (user_id,recurring_expense_id,occurrence_month,due_date) SELECT :uid,id,'2026-01-01','2026-01-31' FROM recurring_expenses WHERE user_id=:uid ORDER BY id LIMIT 1")->execute([':uid'=>$userId]);
    $pdo->prepare("INSERT INTO fund_entries (fund_entry_id,user_id,fund_id,entry_date,entry_type,direction,amount,source_type,source_transaction_id,note) VALUES (:entry,:uid,:fund,'2026-01-05','contribution','in','50.00','manual',NULL,'manual synthetic contribution')")->execute([':entry'=>"phase5-{$suffix}-entry-manual",':uid'=>$userId,':fund'=>$fundId]);
    $pdo->prepare("INSERT INTO fund_entries (fund_entry_id,user_id,fund_id,entry_date,entry_type,direction,amount,source_type,source_transaction_id,note) VALUES (:entry,:uid,:fund,'2026-01-15','contribution','in','25.00','transaction',:txn,'transaction-linked contribution')")->execute([':entry'=>"phase5-{$suffix}-entry-txn",':uid'=>$userId,':fund'=>$fundId,':txn'=>$transactionId]);
    $pdo->prepare("INSERT INTO monthly_savings_allocations (user_id,month,fund_id,planned_amount) VALUES (:uid,'2026-01-01',:fund,'25.00')")->execute([':uid'=>$userId,':fund'=>$fundId]);
    $pdo->prepare("INSERT INTO monthly_closeouts (closeout_id,user_id,month,status,result_type,budget_effective_month,budget_allocation_mode,monthly_income_snapshot,surplus_amount,calculation_hash,closed_at) VALUES (:closeout,:uid,'2026-01-01','closed','surplus','2026-01-01','percent','500.00','5.00',:hash,'2026-02-01 00:00:00')")->execute([':closeout'=>"phase5-{$suffix}-closeout",':uid'=>$userId,':hash'=>hash('sha256',"phase5-{$suffix}-closeout")]); $closeoutId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO monthly_closeout_allocations (allocation_id,closeout_id,user_id,allocation_type,fund_id,amount,superseded_at) VALUES (:allocation,:closeout,:uid,'fund',:fund,'10.00','2026-02-02 00:00:00')")->execute([':allocation'=>"phase5-{$suffix}-allocation-old",':closeout'=>$closeoutId,':uid'=>$userId,':fund'=>$fundId]);
    $pdo->prepare("INSERT INTO monthly_closeout_allocations (allocation_id,closeout_id,user_id,allocation_type,fund_id,amount) VALUES (:allocation,:closeout,:uid,'fund',:fund,'20.00')")->execute([':allocation'=>"phase5-{$suffix}-allocation-current",':closeout'=>$closeoutId,':uid'=>$userId,':fund'=>$fundId]); $alloc=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO fund_entries (fund_entry_id,user_id,fund_id,entry_date,entry_type,direction,amount,source_type,source_closeout_id,source_closeout_allocation_id,note) VALUES (:entry,:uid,:fund,'2026-02-01','contribution','in','20.00','month_closeout',:closeout,:alloc,'closeout-linked contribution')")->execute([':entry'=>"phase5-{$suffix}-entry-closeout",':uid'=>$userId,':fund'=>$fundId,':closeout'=>$closeoutId,':alloc'=>$alloc]);
    $pdo->commit();
    echo json_encode(['email'=>$email,'password'=>$password,'user_id'=>$userId,'tag_id'=>$tagId,'canary'=>'PHASE5_BROWSER_CANARY_'.$suffix,'session_token'=>$sessionId.'.'.$sessionSecret,'csrf_token'=>$csrfToken],JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
