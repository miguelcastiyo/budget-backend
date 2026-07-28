<?php

declare(strict_types=1);

if (getenv('PRIVACY_PARITY_TEST') !== '1') {
    fwrite(STDERR, "Refusing Phase 5 browser assertion: set PRIVACY_PARITY_TEST=1\n");
    exit(2);
}
$dsn = (string) getenv('DB_DSN');
if (!preg_match('/dbname=[^;]*_privacy_parity_test(?:;|$)/', $dsn)) {
    fwrite(STDERR, "Refusing non-parity database\n");
    exit(2);
}
if (($argv[1] ?? '') === '') {
    fwrite(STDERR, "Usage: php scripts/assert_phase5_browser_account.php email [minimum_cancelled_runs]\n");
    exit(2);
}

require __DIR__ . '/../src/bootstrap.php';
$pdo = new PDO($dsn, (string) getenv('DB_USER'), (string) getenv('DB_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$email = (string) $argv[1];
$minimumCancelled = max(0, (int) ($argv[2] ?? 0));
$failures = [];
$one = static function (PDO $pdo, string $sql, array $params = []): mixed {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
};
$userId = (int) $one($pdo, 'SELECT id FROM users WHERE email = :email LIMIT 1', [':email' => $email]);
if ($userId < 1) throw new RuntimeException('browser seed user not found');
$suffix = preg_replace('/^phase5-browser-|@example\.test$/', '', $email);
$canary = 'PHASE5_BROWSER_CANARY_' . $suffix;

$state = (string) $one($pdo, 'SELECT financial_privacy_state FROM users WHERE id = :id', [':id' => $userId]);
if ($state !== 'migration_in_progress') $failures[] = "unexpected privacy state {$state}";
$active = (int) $one($pdo, "SELECT COUNT(*) FROM financial_privacy_migrations WHERE user_id=:id AND status='active'", [':id' => $userId]);
if ($active !== 1) $failures[] = "expected one active migration, got {$active}";
$cancelled = (int) $one($pdo, "SELECT COUNT(*) FROM financial_privacy_migrations WHERE user_id=:id AND status='cancelled'", [':id' => $userId]);
if ($cancelled < $minimumCancelled) $failures[] = "expected at least {$minimumCancelled} cancelled migration(s), got {$cancelled}";

$migration = $pdo->prepare("SELECT migration_id FROM financial_privacy_migrations WHERE user_id=:id AND status='active' ORDER BY id DESC LIMIT 1");
$migration->execute([':id' => $userId]);
$migrationId = (string) $migration->fetchColumn();
if ($migrationId === '') $failures[] = 'active migration id missing';
else {
    $manifest = $pdo->prepare('SELECT target_count, verified_at, finalized_at FROM encrypted_migration_manifests WHERE user_id=:id AND migration_id=:migration LIMIT 1');
    $manifest->execute([':id' => $userId, ':migration' => $migrationId]);
    $manifestRow = $manifest->fetch();
    if (!is_array($manifestRow) || $manifestRow['verified_at'] === null || $manifestRow['finalized_at'] === null) $failures[] = 'active migration manifest is not finalized and verified';
    else {
        $targetCount = (int) $manifestRow['target_count'];
        $stagedCount = (int) $one($pdo, 'SELECT COUNT(*) FROM encrypted_migration_records WHERE user_id=:id AND migration_id=:migration', [':id' => $userId, ':migration' => $migrationId]);
        $distinctCount = (int) $one($pdo, 'SELECT COUNT(DISTINCT target_record_id) FROM encrypted_migration_records WHERE user_id=:id AND migration_id=:migration', [':id' => $userId, ':migration' => $migrationId]);
        $ciphertextCount = (int) $one($pdo, 'SELECT COUNT(*) FROM encrypted_migration_records WHERE user_id=:id AND migration_id=:migration AND OCTET_LENGTH(ciphertext)>0', [':id' => $userId, ':migration' => $migrationId]);
        if ($stagedCount !== $targetCount) $failures[] = "staged count {$stagedCount} != manifest target count {$targetCount}";
        if ($distinctCount !== $stagedCount) $failures[] = 'duplicate target record ids found';
        if ($ciphertextCount !== $stagedCount) $failures[] = 'staging ciphertext is empty for one or more records';
    }
}

$canaryCount = (int) $one($pdo, 'SELECT COUNT(*) FROM transactions WHERE user_id=:id AND expense=:canary', [':id' => $userId, ':canary' => $canary]);
if ($canaryCount !== 1) $failures[] = "authoritative canary count is {$canaryCount}";
$cleanupCount = (int) $one($pdo, 'SELECT COUNT(*) FROM financial_privacy_cleanup_jobs WHERE user_id=:id', [':id' => $userId]);
if ($cleanupCount !== 0) $failures[] = "cleanup jobs unexpectedly present: {$cleanupCount}";
if ($pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='encrypted_financial_records'")->fetchColumn()) {
    $encryptedCount = (int) $one($pdo, 'SELECT COUNT(*) FROM encrypted_financial_records WHERE user_id=:id', [':id' => $userId]);
    if ($encryptedCount !== 0) $failures[] = "encrypted financial records unexpectedly present: {$encryptedCount}";
}
$auditCanary = (int) $one($pdo, "SELECT COUNT(*) FROM audit_logs WHERE actor_user_id=:id AND CAST(metadata AS CHAR) LIKE :canary", [':id' => $userId, ':canary' => '%' . $canary . '%']);
if ($auditCanary !== 0) $failures[] = "canary appeared in audit metadata: {$auditCanary}";

foreach (['encrypted_migration_manifests', 'encrypted_migration_records'] as $table) {
    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');
    $stmt->execute([':table' => $table]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
        if (preg_match('/(^|_)(expense|amount|category|notes?|description|income|name|plaintext|plain_text)($|_)/i', (string) $column)) $failures[] = "plaintext-like staging column {$table}.{$column}";
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo json_encode(['ok' => true, 'user_id' => $userId, 'migration_id' => $migrationId, 'cancelled_runs' => $cancelled, 'canary' => 'not_present_in_operational_metadata'], JSON_UNESCAPED_SLASHES) . "\n";
