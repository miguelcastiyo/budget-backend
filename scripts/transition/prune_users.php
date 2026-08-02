<?php

declare(strict_types=1);

const PHASE4A_ENVIRONMENTS = ['production' => 1, 'local' => 3];

function phase4aUsage(): void
{
    fwrite(STDERR, "Usage: php scripts/transition/prune_users.php --environment=production|local --preserve-user-id=1|3 [--dry-run|--confirm-delete]\n");
}

function phase4aFail(string $message): never
{
    fwrite(STDERR, "Phase 4A pruning refused: {$message}\n");
    exit(2);
}

function phase4aParseArgs(array $argv): array
{
    $options = ['environment' => null, 'preserve_user_id' => null, 'dry_run' => false, 'confirm_delete' => false];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--dry-run') { $options['dry_run'] = true; continue; }
        if ($argument === '--confirm-delete') { $options['confirm_delete'] = true; continue; }
        if ($argument === '--help') { phase4aUsage(); exit(0); }
        if (str_starts_with($argument, '--environment=')) { $options['environment'] = substr($argument, 14); continue; }
        if (str_starts_with($argument, '--preserve-user-id=')) {
            $raw = substr($argument, 19);
            if (!preg_match('/^[1-9][0-9]*$/', $raw)) phase4aFail('preserved user ID must be a positive integer');
            $options['preserve_user_id'] = (int) $raw;
            continue;
        }
        phase4aFail("unknown option {$argument}");
    }
    if (!is_string($options['environment']) || !array_key_exists($options['environment'], PHASE4A_ENVIRONMENTS)) phase4aFail('environment must be exactly production or local');
    if (!is_int($options['preserve_user_id'])) phase4aFail('preserved user ID is required');
    if ($options['preserve_user_id'] !== PHASE4A_ENVIRONMENTS[$options['environment']]) phase4aFail('environment and preserved user ID do not match the approved mapping');
    if ($options['dry_run'] && $options['confirm_delete']) phase4aFail('--dry-run and --confirm-delete cannot be combined');
    if (!$options['dry_run'] && !$options['confirm_delete']) $options['dry_run'] = true;
    return $options;
}

function phase4aTables(PDO $pdo): array
{
    return array_fill_keys(array_map('strval', $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN)), true);
}

function phase4aCount(PDO $pdo, string $table, string $predicate, array $parameters = []): int
{
    $statement = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$predicate}");
    $statement->execute($parameters);
    return (int) $statement->fetchColumn();
}

function phase4aColumnExists(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name");
    $statement->execute([':table_name' => $table, ':column_name' => $column]);
    return (int) $statement->fetchColumn() > 0;
}

function phase4aOwnershipMap(): array
{
    return [
        'user_sessions' => 'user_id', 'user_financial_vaults' => 'user_id', 'vault_quick_unlock_credentials' => 'user_id', 'webauthn_challenges' => 'user_id',
        'encrypted_record_sync_state' => 'user_id', 'encrypted_record_batches' => 'user_id', 'encrypted_financial_records' => 'user_id', 'encrypted_record_changes' => 'user_id',
        'email_change_requests' => 'user_id', 'password_reset_requests' => 'user_id', 'audit_logs' => 'actor_user_id',
        'financial_privacy_migrations' => 'user_id', 'financial_privacy_cleanup_jobs' => 'user_id', 'encrypted_migration_manifests' => 'user_id', 'encrypted_migration_records' => 'user_id',
        'tags' => 'user_id', 'cards' => 'user_id', 'contexts' => 'user_id', 'funds' => 'user_id', 'monthly_savings_allocations' => 'user_id', 'recurring_expenses' => 'user_id',
        'budget_settings' => 'user_id', 'budget_settings_versions' => 'user_id', 'monthly_closeouts' => 'user_id', 'monthly_closeout_allocations' => 'user_id',
        'transactions' => 'user_id', 'recurring_expense_occurrences' => 'user_id', 'fund_entries' => 'user_id', 'csv_import_runs' => 'user_id', 'csv_export_runs' => 'user_id',
    ];
}

function phase4aRejectUnknownUserOwnership(PDO $pdo, array $tables, array $ownership): void
{
    $known = array_fill_keys(array_merge(array_keys($ownership), ['users', 'invitations']), true);
    $rows = $pdo->query("SELECT DISTINCT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('user_id', 'actor_user_id', 'invited_by_user_id', 'accepted_by_user_id') ORDER BY TABLE_NAME, COLUMN_NAME")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $table = (string) $row['TABLE_NAME'];
        if (isset($tables[$table]) && !isset($known[$table])) phase4aFail("unclassified user ownership exists in {$table}");
    }
}

function phase4aValidate(PDO $pdo, array $options, array $tables, array $ownership): array
{
    $preservedUserId = $options['preserve_user_id'];
    $user = $pdo->prepare('SELECT id, financial_privacy_state, is_active FROM users WHERE id = :id FOR UPDATE');
    $user->execute([':id' => $preservedUserId]);
    $preserved = $user->fetch(PDO::FETCH_ASSOC);
    if (!is_array($preserved)) phase4aFail('preserved user does not exist');
    if ((string) $preserved['financial_privacy_state'] !== 'encrypted') phase4aFail('preserved user is not encrypted');
    if ((int) $preserved['is_active'] !== 1) phase4aFail('preserved user is inactive');
    foreach (['user_financial_vaults', 'encrypted_record_sync_state', 'encrypted_financial_records'] as $requiredTable) {
        if (!isset($tables[$requiredTable])) phase4aFail("required encrypted table is missing: {$requiredTable}");
    }
    if (phase4aCount($pdo, 'user_financial_vaults', 'user_id = :id', [':id' => $preservedUserId]) !== 1) phase4aFail('preserved user lacks exactly one Vault record');
    if (phase4aCount($pdo, 'encrypted_record_sync_state', 'user_id = :id', [':id' => $preservedUserId]) !== 1) phase4aFail('preserved user lacks encrypted sync state');
    if (phase4aCount($pdo, 'encrypted_financial_records', 'user_id = :id', [':id' => $preservedUserId]) < 1) phase4aFail('preserved user lacks encrypted financial records');

    $totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $deleteUsers = phase4aCount($pdo, 'users', 'id <> :id', [':id' => $preservedUserId]);
    $states = [];
    $stateQuery = $pdo->prepare('SELECT financial_privacy_state, COUNT(*) AS total FROM users WHERE id <> :id GROUP BY financial_privacy_state ORDER BY financial_privacy_state');
    $stateQuery->execute([':id' => $preservedUserId]);
    foreach ($stateQuery->fetchAll(PDO::FETCH_ASSOC) as $row) $states[(string) $row['financial_privacy_state']] = (int) $row['total'];

    $ambiguous = [];
    $counts = [];
    foreach ($ownership as $table => $column) {
        if (!isset($tables[$table])) continue;
        if (!phase4aColumnExists($pdo, $table, $column)) phase4aFail("ownership column is missing for {$table}");
        $counts[$table] = ['delete' => phase4aCount($pdo, $table, "`{$column}` IS NOT NULL AND `{$column}` <> :id", [':id' => $preservedUserId]), 'preserve' => phase4aCount($pdo, $table, "`{$column}` = :id", [':id' => $preservedUserId])];
    }
    if (isset($tables['invitations'])) {
        foreach (['invited_by_user_id', 'accepted_by_user_id'] as $column) if (!phase4aColumnExists($pdo, 'invitations', $column)) phase4aFail("ownership column is missing for invitations.{$column}");
        $ambiguous['invitations'] = phase4aCount($pdo, 'invitations', 'invited_by_user_id IS NULL AND accepted_by_user_id IS NULL');
        $counts['invitations'] = [
            'delete' => phase4aCount($pdo, 'invitations', '(invited_by_user_id IS NOT NULL AND invited_by_user_id <> :invited_id) OR (accepted_by_user_id IS NOT NULL AND accepted_by_user_id <> :accepted_id)', [':invited_id' => $preservedUserId, ':accepted_id' => $preservedUserId]),
            'preserve' => phase4aCount($pdo, 'invitations', '(invited_by_user_id = :invited_id OR invited_by_user_id IS NULL) AND (accepted_by_user_id = :accepted_id OR accepted_by_user_id IS NULL)', [':invited_id' => $preservedUserId, ':accepted_id' => $preservedUserId]),
        ];
    }
    if (array_sum($ambiguous) > 0) phase4aFail('ambiguous ownership exists in the cleanup inventory');
    return ['total_users' => $totalUsers, 'delete_users' => $deleteUsers, 'states' => $states, 'counts' => $counts, 'ambiguous' => $ambiguous];
}

function phase4aDeleteOrder(): array
{
    return [
        ['audit_logs', 'actor_user_id'], ['encrypted_record_changes', 'user_id'], ['encrypted_record_batches', 'user_id'], ['encrypted_financial_records', 'user_id'], ['encrypted_record_sync_state', 'user_id'],
        ['encrypted_migration_records', 'user_id'], ['encrypted_migration_manifests', 'user_id'], ['financial_privacy_cleanup_jobs', 'user_id'], ['financial_privacy_migrations', 'user_id'],
        ['fund_entries', 'user_id'], ['recurring_expense_occurrences', 'user_id'], ['monthly_closeout_allocations', 'user_id'], ['monthly_closeouts', 'user_id'], ['monthly_savings_allocations', 'user_id'], ['transactions', 'user_id'],
        ['csv_import_runs', 'user_id'], ['csv_export_runs', 'user_id'], ['recurring_expenses', 'user_id'], ['budget_settings_versions', 'user_id'], ['budget_settings', 'user_id'],
        ['funds', 'user_id'], ['contexts', 'user_id'], ['cards', 'user_id'], ['tags', 'user_id'], ['email_change_requests', 'user_id'], ['password_reset_requests', 'user_id'],
        ['webauthn_challenges', 'user_id'], ['vault_quick_unlock_credentials', 'user_id'], ['user_sessions', 'user_id'], ['user_financial_vaults', 'user_id'],
    ];
}

function phase4aVerify(PDO $pdo, int $preservedUserId, array $tables, array $ownership): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 1 || (int) $pdo->query('SELECT id FROM users LIMIT 1')->fetchColumn() !== $preservedUserId) phase4aFail('post-cleanup user identity verification failed');
    $state = $pdo->prepare('SELECT financial_privacy_state, is_active FROM users WHERE id = :id');
    $state->execute([':id' => $preservedUserId]);
    $row = $state->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || $row['financial_privacy_state'] !== 'encrypted' || (int) $row['is_active'] !== 1) phase4aFail('post-cleanup preserved-user state verification failed');
    foreach ($ownership as $table => $column) if (isset($tables[$table]) && phase4aCount($pdo, $table, "`{$column}` IS NOT NULL AND `{$column}` <> :id", [':id' => $preservedUserId]) !== 0) phase4aFail("post-cleanup ownership verification failed for {$table}");
    if (isset($tables['invitations']) && phase4aCount($pdo, 'invitations', '(invited_by_user_id IS NOT NULL AND invited_by_user_id <> :invited_id) OR (accepted_by_user_id IS NOT NULL AND accepted_by_user_id <> :accepted_id)', [':invited_id' => $preservedUserId, ':accepted_id' => $preservedUserId]) !== 0) phase4aFail('post-cleanup ownership verification failed for invitations');
}

$options = phase4aParseArgs($argv);
require __DIR__ . '/../../src/bootstrap.php';
$config = App\Core\Config::load(dirname(__DIR__, 2));
$pdo = App\Database\Connection::make($config);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$lock = (int) $pdo->query("SELECT GET_LOCK('budget_phase4a_user_prune', 30)")->fetchColumn();
if ($lock !== 1) phase4aFail('could not acquire the Phase 4A operator lock');
try {
    $tables = phase4aTables($pdo);
    $ownership = phase4aOwnershipMap();
    phase4aRejectUnknownUserOwnership($pdo, $tables, $ownership);
    $pdo->beginTransaction();
    $inventory = phase4aValidate($pdo, $options, $tables, $ownership);
    $report = ['phase4a_version' => 'environment_safe_user_pruning_v1', 'environment' => $options['environment'], 'preserved_user_id' => $options['preserve_user_id'], 'mode' => $options['confirm_delete'] ? 'confirm_delete' : 'dry_run', 'total_users_before' => $inventory['total_users'], 'users_to_delete' => $inventory['delete_users'], 'deleted_user_privacy_states' => $inventory['states'], 'row_counts' => $inventory['counts'], 'ambiguous_ownership' => $inventory['ambiguous'], 'preconditions_passed' => true, 'privacy_safe_scope' => ['includes_identifiers' => false, 'includes_financial_values' => false, 'includes_credentials' => false]];
    if ($options['confirm_delete']) {
        foreach (phase4aDeleteOrder() as [$table, $column]) {
            if (!isset($tables[$table])) continue;
            $statement = $pdo->prepare("DELETE FROM `{$table}` WHERE `{$column}` IS NOT NULL AND `{$column}` <> :id");
            $statement->execute([':id' => $options['preserve_user_id']]);
        }
        if (isset($tables['invitations'])) {
            $statement = $pdo->prepare('DELETE FROM invitations WHERE (invited_by_user_id IS NOT NULL AND invited_by_user_id <> :invited_id) OR (accepted_by_user_id IS NOT NULL AND accepted_by_user_id <> :accepted_id)');
            $statement->execute([':invited_id' => $options['preserve_user_id'], ':accepted_id' => $options['preserve_user_id']]);
        }
        $statement = $pdo->prepare('DELETE FROM users WHERE id <> :id');
        $statement->execute([':id' => $options['preserve_user_id']]);
        phase4aVerify($pdo, $options['preserve_user_id'], $tables, $ownership);
        $report['total_users_after'] = 1;
        $report['users_deleted'] = $inventory['delete_users'];
    }
    if ($options['confirm_delete']) {
        $pdo->commit();
    } else {
        $pdo->rollBack();
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
} finally {
    $pdo->query("SELECT RELEASE_LOCK('budget_phase4a_user_prune')");
}
