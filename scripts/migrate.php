<?php

declare(strict_types=1);

use App\Core\Config;
use App\Database\Connection;

require __DIR__ . '/../src/bootstrap.php';

const MIGRATIONS_TABLE = 'schema_migrations';

$mode = parseCliMode($argv);
if ($mode === 'help') {
    echo usage();
    exit(0);
}

$config = Config::load(dirname(__DIR__));
$pdo = Connection::make($config);

$state = inspectMigrationState($pdo);

if ($mode === 'status') {
    echo renderStatus($state);
    exit(0);
}

applyMigrations($pdo, $state);

/**
 * @return 'apply'|'status'|'help'
 */
function parseCliMode(array $argv): string
{
    $args = array_slice($argv, 1);
    if ($args === []) {
        return 'apply';
    }

    if (count($args) === 1 && ($args[0] === '--help' || $args[0] === '-h')) {
        return 'help';
    }
    if (count($args) === 1 && $args[0] === '--status') {
        return 'status';
    }

    fwrite(STDERR, 'Unknown argument(s): ' . implode(' ', $args) . PHP_EOL);
    fwrite(STDERR, usage());
    exit(2);
}

function usage(): string
{
    return <<<TXT
Usage:
  php scripts/migrate.php            Apply migrations (default)
  php scripts/migrate.php --status   Show migration status only (no changes)
  php scripts/migrate.php --help     Show this help

TXT;
}

/**
 * @return array{
 *   schema_exists: bool,
 *   migrations_table_exists: bool,
 *   migration_files: list<string>,
 *   applied_migrations: list<string>,
 *   pending_migrations: list<string>,
 *   action_summary: string
 * }
 */
function inspectMigrationState(PDO $pdo): array
{
    $schemaExists = applicationSchemaExists($pdo);
    $migrationsTableExists = migrationsTableExists($pdo);
    $migrationFiles = migrationFiles();
    $appliedMigrations = $migrationsTableExists ? appliedMigrations($pdo) : [];

    $pendingMigrations = pendingMigrations($migrationFiles, $appliedMigrations);

    if (!$schemaExists) {
        $actionSummary = 'would apply schema.sql and mark all migrations applied.';
    } elseif ($appliedMigrations === []) {
        $actionSummary = 'would baseline existing schema by marking all migrations applied.';
    } elseif ($pendingMigrations !== []) {
        $actionSummary = 'would apply pending migrations in filename order.';
    } else {
        $actionSummary = 'no migration action needed.';
    }

    return [
        'schema_exists' => $schemaExists,
        'migrations_table_exists' => $migrationsTableExists,
        'migration_files' => $migrationFiles,
        'applied_migrations' => $appliedMigrations,
        'pending_migrations' => $pendingMigrations,
        'action_summary' => $actionSummary,
    ];
}

/**
 * @param array{
 *   schema_exists: bool,
 *   migrations_table_exists: bool,
 *   migration_files: list<string>,
 *   applied_migrations: list<string>,
 *   pending_migrations: list<string>,
 *   action_summary: string
 * } $state
 */
function renderStatus(array $state): string
{
    $lines = [];
    $lines[] = 'Application schema exists: ' . ($state['schema_exists'] ? 'yes' : 'no');
    $lines[] = 'schema_migrations exists: ' . ($state['migrations_table_exists'] ? 'yes' : 'no');
    $lines[] = 'Migration files found: ' . count($state['migration_files']);
    $lines[] = 'Applied migrations recorded: ' . count($state['applied_migrations']);
    $lines[] = 'Pending migrations: ' . count($state['pending_migrations']);
    $lines[] = '';
    $lines[] = 'Action summary: ' . $state['action_summary'];

    if ($state['pending_migrations'] !== []) {
        $lines[] = '';
        $lines[] = 'Pending migration filenames:';
        foreach ($state['pending_migrations'] as $path) {
            $lines[] = basename($path);
        }
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

/**
 * @param list<string> $migrationFiles
 * @param list<string> $appliedMigrations
 * @return list<string>
 */
function pendingMigrations(array $migrationFiles, array $appliedMigrations): array
{
    return array_values(array_filter(
        $migrationFiles,
        static fn(string $migration): bool => !in_array(basename($migration), $appliedMigrations, true)
    ));
}

/**
 * @param array{
 *   schema_exists: bool,
 *   migrations_table_exists: bool,
 *   migration_files: list<string>,
 *   applied_migrations: list<string>,
 *   pending_migrations: list<string>,
 *   action_summary: string
 * } $state
 */
function applyMigrations(PDO $pdo, array $state): void
{
    ensureMigrationsTable($pdo);

    // A pre-migration database may be baselined without replaying historical
    // files. Ensure the encrypted-by-default lifecycle DDL is present before
    // that baseline is recorded. If the lifecycle file is already recorded
    // but the live column is stale, repair the schema rather than trusting the
    // ledger alone.
    $lifecycleMigration = '20260727_encrypted_by_default_account_lifecycle.sql';
    $lifecycleRecorded = in_array($lifecycleMigration, $state['applied_migrations'], true);
    $lifecyclePending = in_array(__DIR__ . '/../migrations/' . $lifecycleMigration, $state['pending_migrations'], true);
    if ($state['schema_exists'] && ($state['applied_migrations'] === [] || ($lifecycleRecorded && !$lifecyclePending))) {
        ensureEncryptedByDefaultLifecycle($pdo);
    }

    if (!$state['schema_exists']) {
        applySqlFile($pdo, __DIR__ . '/../schema.sql');
        markMigrationsApplied($pdo, $state['migration_files']);
        echo "Schema applied successfully.\n";
        exit(0);
    }

    if ($state['applied_migrations'] === []) {
        markMigrationsApplied($pdo, $state['migration_files']);
        echo "Existing schema detected; baseline migrations recorded.\n";
        exit(0);
    }

    if ($state['pending_migrations'] === []) {
        echo "No pending migrations.\n";
        exit(0);
    }

    foreach ($state['pending_migrations'] as $migrationFile) {
        applySqlFile($pdo, $migrationFile);
        recordMigration($pdo, basename($migrationFile));
        echo "Applied migration: " . basename($migrationFile) . "\n";
    }

    echo "All pending migrations applied successfully.\n";
    exit(0);
}

function ensureEncryptedByDefaultLifecycle(PDO $pdo): void
{
    $check = $pdo->prepare('SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'users\' AND COLUMN_NAME = \'financial_privacy_state\' LIMIT 1');
    $check->execute();
    $default = $check->fetchColumn();
    if ($default === false) {
        throw new RuntimeException('users.financial_privacy_state is missing; refusing to baseline the schema');
    }
    if (trim((string) $default, "'\"") === 'vault_setup_required') return;

    $migration = file_get_contents(__DIR__ . '/../migrations/20260727_encrypted_by_default_account_lifecycle.sql');
    if ($migration === false) throw new RuntimeException('Encrypted-by-default lifecycle migration could not be read');
    $pdo->exec($migration);
    echo "Repaired encrypted-by-default lifecycle schema.\n";
}

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

function migrationsTableExists(PDO $pdo): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $stmt->execute([':table_name' => MIGRATIONS_TABLE]);

    return (int) $stmt->fetchColumn() > 0;
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
        if (basename($path) === '20260801_retire_plaintext_financial_schema.sql' && str_contains($e->getMessage(), 'phase4_schema_guard_failure')) {
            fwrite(STDERR, "Phase 4 schema retirement refused: legacy or transition-state accounts still exist. A dry-run does not delete accounts; after reviewing its aggregate report, rerun the environment-safe pruning command with --confirm-delete, then retry this migration.\n");
            exit(1);
        }
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
