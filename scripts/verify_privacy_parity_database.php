<?php

declare(strict_types=1);

if (getenv('PRIVACY_PARITY_TEST') !== '1') {
    fwrite(STDERR, "Refusing parity database verification: set PRIVACY_PARITY_TEST=1\n");
    exit(2);
}
$database = getenv('PRIVACY_PARITY_DB_NAME') ?: 'budget_privacy_parity_test';
if (!preg_match('/_privacy_parity_test$/', $database)) {
    fwrite(STDERR, "Refusing verification outside an explicitly named parity database\n");
    exit(2);
}
$host = getenv('PRIVACY_PARITY_DB_HOST') ?: '127.0.0.1';
$port = getenv('PRIVACY_PARITY_DB_PORT') ?: '3306';
$user = getenv('PRIVACY_PARITY_DB_USER') ?: 'root';
$pass = getenv('PRIVACY_PARITY_DB_PASS') ?: '';
$root = dirname(__DIR__);
try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $schema = file_get_contents($root . '/schema.sql') ?: '';
    preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)? `?([a-zA-Z0-9_]+)`?/i', $schema, $matches);
    $expected = array_values(array_unique($matches[1] ?? []));
    $actual = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff($expected, $actual));
    $migrationFiles = glob($root . '/migrations/*.sql') ?: [];
    $migrationCount = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    if ($missing !== [] || $migrationCount !== count($migrationFiles)) throw new RuntimeException('schema verification failed');
    echo sprintf("Parity schema verified: %d canonical tables, %d migration baselines\n", count($expected), $migrationCount);
} catch (Throwable $e) {
    fwrite(STDERR, "Parity schema verification failed: {$e->getMessage()}\n");
    exit(1);
}
