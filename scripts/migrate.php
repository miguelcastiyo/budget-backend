<?php

declare(strict_types=1);

use App\Core\Config;
use App\Database\Connection;

require __DIR__ . '/../src/bootstrap.php';

const MIGRATIONS_TABLE = 'schema_migrations';

$config = Config::load(dirname(__DIR__));
$pdo = Connection::make($config);

ensureMigrationsTable($pdo);

$migrationFiles = migrationFiles();
$appliedMigrations = appliedMigrations($pdo);
$schemaInitialized = applicationSchemaExists($pdo);

if (!$schemaInitialized) {
    applySqlFile($pdo, __DIR__ . '/../schema.sql');
    markMigrationsApplied($pdo, $migrationFiles);
    echo "Schema applied successfully.\n";
    exit(0);
}

if ($appliedMigrations === []) {
    markMigrationsApplied($pdo, $migrationFiles);
    echo "Existing schema detected; baseline migrations recorded.\n";
    exit(0);
}

$pendingMigrations = array_values(array_filter(
    $migrationFiles,
    static fn(string $migration): bool => !in_array(basename($migration), $appliedMigrations, true)
));

if ($pendingMigrations === []) {
    echo "No pending migrations.\n";
    exit(0);
}

foreach ($pendingMigrations as $migrationFile) {
    applySqlFile($pdo, $migrationFile);
    recordMigration($pdo, basename($migrationFile));
    echo "Applied migration: " . basename($migrationFile) . "\n";
}

echo "All pending migrations applied successfully.\n";

function ensureMigrationsTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS ' . MIGRATIONS_TABLE . ' (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration_name VARCHAR(255) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_schema_migrations_name (migration_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * @return list<string>
 */
function migrationFiles(): array
{
    $files = glob(__DIR__ . '/../migrations/*.sql') ?: [];
    sort($files, SORT_STRING);

    return array_values($files);
}

/**
 * @return list<string>
 */
function appliedMigrations(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT migration_name FROM ' . MIGRATIONS_TABLE . ' ORDER BY migration_name ASC');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

    return array_values(array_filter($rows, static fn(mixed $row): bool => is_string($row) && $row !== ''));
}

function applicationSchemaExists(PDO $pdo): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $stmt->execute([':table_name' => 'users']);

    return (int) $stmt->fetchColumn() > 0;
}

function applySqlFile(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        fwrite(STDERR, 'Failed to read SQL file: ' . $path . PHP_EOL);
        exit(1);
    }

    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Migration failed for ' . basename($path) . ': ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

/**
 * @param list<string> $migrationFiles
 */
function markMigrationsApplied(PDO $pdo, array $migrationFiles): void
{
    foreach ($migrationFiles as $migrationFile) {
        recordMigration($pdo, basename($migrationFile));
    }
}

function recordMigration(PDO $pdo, string $migrationName): void
{
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO ' . MIGRATIONS_TABLE . ' (migration_name) VALUES (:migration_name)'
    );
    $stmt->execute([':migration_name' => $migrationName]);
}
