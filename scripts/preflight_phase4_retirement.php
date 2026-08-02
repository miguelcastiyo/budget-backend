<?php

declare(strict_types=1);

if (getenv('PHASE4_PREFLIGHT_CONFIRM') !== '1') {
    fwrite(STDERR, "Refusing Phase 4 preflight: set PHASE4_PREFLIGHT_CONFIRM=1\n");
    exit(2);
}

require __DIR__ . '/../src/bootstrap.php';

$config = App\Core\Config::load(dirname(__DIR__));
$pdo = App\Database\Connection::make($config);
$required = [
    'users', 'user_sessions', 'user_financial_vaults', 'vault_quick_unlock_credentials',
    'webauthn_challenges', 'encrypted_record_sync_state', 'encrypted_record_batches', 'encrypted_financial_records',
    'encrypted_record_changes', 'audit_logs',
];
$candidates = [
    'financial_privacy_migrations', 'financial_privacy_cleanup_jobs',
    'encrypted_migration_manifests', 'encrypted_migration_records',
    'tags', 'cards', 'contexts', 'funds', 'monthly_savings_allocations',
    'recurring_expenses', 'budget_settings', 'budget_settings_versions',
    'monthly_closeouts', 'monthly_closeout_allocations', 'transactions',
    'recurring_expense_occurrences', 'fund_entries', 'csv_import_runs', 'csv_export_runs',
];

$present = array_fill_keys(array_map('strval', $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN)), true);
$states = [];
foreach ($pdo->query('SELECT financial_privacy_state, COUNT(*) AS total FROM users GROUP BY financial_privacy_state ORDER BY financial_privacy_state')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $states[(string) $row['financial_privacy_state']] = (int) $row['total'];
}
$legacyAccounts = array_sum(array_diff_key($states, ['vault_setup_required' => true, 'encrypted' => true]));
$missingRequired = array_values(array_filter($required, static fn(string $table): bool => !isset($present[$table])));
$presentCandidates = array_values(array_filter($candidates, static fn(string $table): bool => isset($present[$table])));

$report = [
    'preflight_version' => 'phase4_v1',
    'database_schema' => 'selected',
    'required_tables_present' => count($required) - count($missingRequired),
    'required_tables_expected' => count($required),
    'candidate_tables_present' => count($presentCandidates),
    'candidate_tables_expected' => count($candidates),
    'privacy_state_counts' => $states,
    'legacy_or_transition_account_count' => $legacyAccounts,
    'safe_to_apply' => $legacyAccounts === 0 && $missingRequired === [],
    'privacy_safe_scope' => ['includes_identifiers' => false, 'includes_financial_values' => false, 'read_only' => true],
];
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ($missingRequired !== [] || $legacyAccounts !== 0) exit(1);
