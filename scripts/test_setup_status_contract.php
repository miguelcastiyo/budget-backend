<?php

declare(strict_types=1);

/* Setup status is account UI metadata; financial facts belong to the client. */
$source = file_get_contents(dirname(__DIR__) . '/src/Controllers/ProfileController.php');
if ($source === false) {
    throw new RuntimeException('setup-status contract could not read ProfileController');
}

foreach (['budget_settings', 'transactions', 'recurring_expenses', 'csv_import_runs', 'funds', 'fund_entries', 'month_closeouts'] as $table) {
    if (preg_match('/\b(?:FROM|JOIN|UPDATE|INTO|DELETE\s+FROM)\s+' . preg_quote($table, '/') . '\b/i', $source) === 1) {
        throw new RuntimeException("setup-status contract found plaintext financial table access: {$table}");
    }
}

foreach (['budget_profile_complete', 'has_transactions', 'has_recurring_expenses', 'has_imported_data', 'onboarding_dismissed', 'recommended_next_action', 'setup_tasks'] as $field) {
    if (!str_contains($source, "'{$field}'")) {
        throw new RuntimeException("setup-status contract field is missing: {$field}");
    }
}

if (!str_contains($source, "'recommended_next_action' => 'none'")) {
    throw new RuntimeException('setup-status must not infer a financial next action from unavailable plaintext data');
}

echo "Setup-status contract passed: account metadata does not read plaintext financial tables\n";
