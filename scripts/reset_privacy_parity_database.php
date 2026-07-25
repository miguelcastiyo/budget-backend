<?php

declare(strict_types=1);

if (getenv('PRIVACY_PARITY_TEST') !== '1') {
    fwrite(STDERR, "Refusing parity database reset: set PRIVACY_PARITY_TEST=1\n");
    exit(2);
}
$database = getenv('PRIVACY_PARITY_DB_NAME') ?: 'budget_privacy_parity_test';
if (!preg_match('/_privacy_parity_test$/', $database)) {
    fwrite(STDERR, "Refusing reset outside an explicitly named parity database\n");
    exit(2);
}
$host = getenv('PRIVACY_PARITY_DB_HOST') ?: '127.0.0.1';
$port = getenv('PRIVACY_PARITY_DB_PORT') ?: '3306';
$user = getenv('PRIVACY_PARITY_DB_USER') ?: 'root';
$pass = getenv('PRIVACY_PARITY_DB_PASS') ?: '';
try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME <> 'schema_migrations'")->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $table) $pdo->exec('TRUNCATE TABLE `' . str_replace('`', '``', (string) $table) . '`');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    echo "Privacy parity database reset: {$database}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Parity database reset failed: {$e->getMessage()}\n");
    exit(1);
}
