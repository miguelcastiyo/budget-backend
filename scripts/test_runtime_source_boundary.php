<?php

declare(strict_types=1);

/*
 * Static Phase 3B contract for the normal backend runtime. Legacy source may
 * remain for isolated transition tests/tools, but App and retained account /
 * security controllers must not construct or import it.
 */
$root = dirname(__DIR__);
$app = (string) file_get_contents($root . '/src/Core/App.php');
$profile = (string) file_get_contents($root . '/src/Controllers/ProfileController.php');
if ($app === '' || $profile === '') {
    throw new RuntimeException('runtime source boundary could not read required sources');
}

$retiredRuntimeMarkers = [
    'BudgetSettingsController',
    'FundController',
    'ImportExportController',
    'MetricsController',
    'MonthCloseoutController',
    'MonthOverviewController',
    'RecurringExpenseController',
    'SavingsPlanController',
    'TaxonomyController',
    'TransactionController',
    'FinancialReadPolicy',
    'FinancialWritePolicy',
    'BudgetSettingsResolver',
    'RecurringExpenseService',
    'FundService',
    'CsvImportService',
    'CsvExportService',
    'MonthCloseoutService',
    'MonthOverviewService',
    'SavingsPlanService',
];
foreach ($retiredRuntimeMarkers as $marker) {
    if (str_contains($app, $marker)) {
        throw new RuntimeException("public runtime wiring references retired plaintext implementation: {$marker}");
    }
}

foreach (['budget_settings', 'transactions', 'recurring_expenses', 'csv_import_runs', 'funds', 'fund_entries', 'month_closeouts'] as $table) {
    if (preg_match('/\b(?:FROM|JOIN|UPDATE|INTO|DELETE\s+FROM)\s+' . preg_quote($table, '/') . '\b/i', $profile) === 1) {
        throw new RuntimeException("profile request handling references legacy financial table: {$table}");
    }
}

echo "Runtime source boundary passed: public App wiring and profile handling contain no retired plaintext financial dependencies\n";
