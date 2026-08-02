<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$schema = file_get_contents($root . '/schema.sql');
$migration = file_get_contents($root . '/migrations/20260801_retire_plaintext_financial_schema.sql');
if ($schema === false || $migration === false) {
    throw new RuntimeException('Phase 4 schema contract could not read schema or migration');
}

$required = [
    'users', 'invitations', 'user_sessions', 'user_financial_vaults',
    'vault_quick_unlock_credentials', 'webauthn_challenges',
    'encrypted_record_sync_state', 'encrypted_record_batches', 'encrypted_financial_records',
    'encrypted_record_changes', 'email_change_requests',
    'password_reset_requests', 'audit_logs',
];
$forbidden = [
    'financial_privacy_migrations', 'financial_privacy_cleanup_jobs',
    'encrypted_migration_manifests', 'encrypted_migration_records',
    'tags', 'cards', 'contexts', 'funds', 'monthly_savings_allocations',
    'recurring_expenses', 'budget_settings', 'budget_settings_versions',
    'monthly_closeouts', 'monthly_closeout_allocations', 'transactions',
    'recurring_expense_occurrences', 'fund_entries', 'csv_import_runs',
    'csv_export_runs',
];

preg_match_all('/^CREATE TABLE ([a-z0-9_]+)/m', $schema, $matches);
$actual = array_values(array_unique($matches[1] ?? []));
sort($actual);
$expected = $required;
sort($expected);
if ($actual !== $expected) {
    throw new RuntimeException('schema.sql table set is not the encrypted-only contract: ' . json_encode(['actual' => $actual, 'expected' => $expected], JSON_UNESCAPED_SLASHES));
}

foreach ($forbidden as $table) {
    if (preg_match('/^CREATE TABLE ' . preg_quote($table, '/') . '\b/m', $schema) === 1) {
        throw new RuntimeException("schema.sql reintroduces retired table: {$table}");
    }
    if (!str_contains($migration, 'DROP TABLE IF EXISTS ' . $table . ';')) {
        throw new RuntimeException("Phase 4 migration does not retire table: {$table}");
    }
}

foreach (['encrypted_record_sync_state', 'encrypted_financial_records', 'encrypted_record_changes', 'audit_logs'] as $table) {
    if (!str_contains($schema, 'CREATE TABLE ' . $table . ' (')) {
        throw new RuntimeException("encrypted-era table is missing: {$table}");
    }
}

if (!str_contains($schema, "financial_privacy_state IN ('vault_setup_required', 'encrypted')")) {
    throw new RuntimeException('schema.sql retains retired privacy states');
}
if (!str_contains($migration, "financial_privacy_state IN ('vault_setup_required', 'encrypted')")) {
    throw new RuntimeException('Phase 4 migration does not enforce encrypted-only privacy states');
}
if (!str_contains(file_get_contents($root . '/scripts/migrate.php') ?: '', 'dry-run does not delete accounts')) {
    throw new RuntimeException('migration runner does not explain the Phase 4A prerequisite');
}

echo "Phase 4 schema contract passed: current schema has " . count($actual) . " encrypted-era tables and all retired tables are forward-dropped\n";
