<?php

declare(strict_types=1);

if (getenv('PRIVACY_PARITY_TEST') !== '1') {
    fwrite(STDERR, "Refusing parity database setup: set PRIVACY_PARITY_TEST=1\n");
    exit(2);
}

$root = dirname(__DIR__);
$env = [];
foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$host = getenv('PRIVACY_PARITY_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$port = getenv('PRIVACY_PARITY_DB_PORT') ?: ($env['DB_PORT'] ?? '3306');
$user = getenv('PRIVACY_PARITY_DB_USER') ?: ($env['DB_USER'] ?? 'root');
$pass = getenv('PRIVACY_PARITY_DB_PASS') ?: ($env['DB_PASS'] ?? '');
$database = getenv('PRIVACY_PARITY_DB_NAME') ?: 'budget_privacy_parity_test';
$productionDsn = $env['DB_DSN'] ?? '';
if (!preg_match('/_privacy_parity_test$/', $database) || $database === 'budget') {
    fwrite(STDERR, "Refusing non-parity database name: {$database}\n");
    exit(2);
}
if (preg_match('/dbname=([^;]+)/', $productionDsn, $match) && $match[1] === $database) {
    fwrite(STDERR, "Refusing configured production database name\n");
    exit(2);
}

try {
    $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server->exec('CREATE DATABASE IF NOT EXISTS `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $schemaExists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")->fetchColumn() > 0;
    if (!$schemaExists) {
        $schema = file_get_contents($root . '/schema.sql');
        if ($schema === false) throw new RuntimeException('schema.sql could not be read');
        $pdo->exec($schema);
    }
    // Existing parity databases may have been baselined before the
    // encrypted-by-default lifecycle migration was introduced. The migration
    // ledger alone is insufficient in that case because the baseline helper
    // intentionally records historical files without replaying them. Repair
    // only this additive lifecycle DDL when the live column default is stale;
    // existing legacy fixture rows remain valid and are not rewritten.
    $defaultCheck = $pdo->prepare('SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'users\' AND COLUMN_NAME = \'financial_privacy_state\' LIMIT 1');
    $defaultCheck->execute();
    if (trim((string) $defaultCheck->fetchColumn(), "'\"") !== 'vault_setup_required') {
        $lifecycleSql = file_get_contents($root . '/migrations/20260727_encrypted_by_default_account_lifecycle.sql');
        if ($lifecycleSql === false) throw new RuntimeException('Encrypted-by-default lifecycle migration could not be read');
        $pdo->exec($lifecycleSql);
    }
    // Existing parity databases predate additive migrations. Apply the Phase 5
    // staging DDL when its canonical tables are not present; it contains no
    // plaintext financial columns and is safe to add without data rewriting.
    $stagingTables = ['encrypted_migration_manifests', 'encrypted_migration_records'];
    foreach ($stagingTables as $stagingTable) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
        $check->execute([':table_name' => $stagingTable]);
        if ((int) $check->fetchColumn() === 0) {
            $stagingSql = file_get_contents($root . '/migrations/20260725_create_encrypted_migration_staging.sql');
            if ($stagingSql === false) throw new RuntimeException('Phase 5 staging migration could not be read');
            $pdo->exec($stagingSql);
            break;
        }
    }
    $batchTableCheck = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'encrypted_record_batches'");
    if ((int) $batchTableCheck->fetchColumn() === 0) {
        $batchSql = file_get_contents($root . '/migrations/20260726_create_encrypted_record_batches.sql');
        if ($batchSql === false) throw new RuntimeException('Encrypted batch migration could not be read');
        $pdo->exec($batchSql);
    }
    // schema.sql is the canonical current schema. Historical migrations are
    // recorded as applied; they are never replayed after the canonical schema.
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, migration_name VARCHAR(255) NOT NULL, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY uq_schema_migrations_name (migration_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    foreach (glob($root . '/migrations/*.sql') ?: [] as $migration) {
        $name = basename($migration);
        $check = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration_name = :name');
        $check->execute([':name' => $name]);
        if ((int) $check->fetchColumn() > 0) continue;
        $insert = $pdo->prepare('INSERT INTO schema_migrations (migration_name) VALUES (:name)');
        $insert->execute([':name' => $name]);
    }
    $expectedTables = [];
    $schemaText = file_get_contents($root . '/schema.sql') ?: '';
    preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)? `?([a-zA-Z0-9_]+)`?/i', $schemaText, $tableMatches);
    foreach ($tableMatches[1] ?? [] as $table) $expectedTables[$table] = true;
    $actualTables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_diff(array_keys($expectedTables), $actualTables);
    if ($missing !== []) throw new RuntimeException('Canonical schema verification failed; missing tables: ' . implode(', ', $missing));
    $migrationCount = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    if ($migrationCount !== count(glob($root . '/migrations/*.sql') ?: [])) throw new RuntimeException('Canonical schema migration baseline is incomplete');
    echo "Privacy parity database ready: {$database}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Parity database setup failed: {$e->getMessage()}\n");
    exit(1);
}
