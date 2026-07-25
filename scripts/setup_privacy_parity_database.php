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
