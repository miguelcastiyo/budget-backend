<?php

declare(strict_types=1);

use App\Budget\BudgetSettingsResolver;
use App\Http\HttpException;
use App\Savings\SavingsPlanService;

require __DIR__ . '/../src/bootstrap.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec('CREATE TABLE budget_settings_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, effective_month TEXT NOT NULL,
    monthly_income TEXT NOT NULL, income_source_type TEXT NOT NULL, primary_monthly_income TEXT,
    primary_hourly_rate TEXT, primary_weekly_hours TEXT, side_income_type TEXT NOT NULL,
    side_income_label TEXT, side_monthly_income TEXT, side_hourly_rate TEXT, side_weekly_hours TEXT,
    allocation_mode TEXT NOT NULL, needs_percent TEXT, wants_percent TEXT, savings_percent TEXT,
    needs_amount TEXT, wants_amount TEXT, savings_amount TEXT, created_at TEXT, updated_at TEXT
)');
$pdo->exec('CREATE TABLE funds (
    id INTEGER PRIMARY KEY AUTOINCREMENT, fund_id TEXT NOT NULL, user_id INTEGER NOT NULL,
    name TEXT NOT NULL, fund_type TEXT NOT NULL, goal_amount TEXT, target_month TEXT,
    notes TEXT, status TEXT NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0, archived_at TEXT,
    created_at TEXT, updated_at TEXT
)');
$pdo->exec('CREATE TABLE monthly_savings_allocations (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, month TEXT NOT NULL,
    fund_id INTEGER NOT NULL, planned_amount TEXT NOT NULL, created_at TEXT, updated_at TEXT
)');
$pdo->exec('CREATE TABLE transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, transaction_date TEXT NOT NULL,
    amount TEXT NOT NULL, category TEXT NOT NULL, deleted_at TEXT
)');
$pdo->exec('CREATE TABLE fund_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, fund_id INTEGER NOT NULL,
    entry_date TEXT NOT NULL, entry_type TEXT NOT NULL, direction TEXT NOT NULL, amount TEXT NOT NULL,
    source_type TEXT NOT NULL, source_transaction_id INTEGER, source_closeout_id INTEGER,
    deleted_at TEXT, voided_at TEXT
)');
$pdo->exec('CREATE TABLE monthly_closeouts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, month TEXT NOT NULL, status TEXT NOT NULL)');

$settings = [
    'user_id' => 1, 'effective_month' => '2026-07-01', 'monthly_income' => '5000.00',
    'income_source_type' => 'monthly', 'allocation_mode' => 'amount', 'needs_amount' => '3000.00',
    'wants_amount' => '1000.00', 'savings_amount' => '1000.00', 'side_income_type' => 'none',
];
$columns = ['user_id', 'effective_month', 'monthly_income', 'income_source_type', 'allocation_mode', 'needs_amount', 'wants_amount', 'savings_amount', 'side_income_type'];
$stmt = $pdo->prepare('INSERT INTO budget_settings_versions (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
$stmt->execute(array_combine(array_map(static fn(string $v): string => ':' . $v, $columns), array_map(static fn(string $v): mixed => $settings[$v], $columns)));
$pdo->exec("INSERT INTO funds (fund_id, user_id, name, fund_type, goal_amount, target_month, status, sort_order) VALUES ('fund_japan', 1, 'Japan', 'goal', '4000.00', '2026-11-01', 'active', 1), ('fund_emergency', 1, 'Emergency', 'emergency', NULL, NULL, 'active', 2)");

$service = new SavingsPlanService($pdo, new BudgetSettingsResolver($pdo));

$openApi = file_get_contents(__DIR__ . '/../openapi.yaml');
$serviceSource = file_get_contents(__DIR__ . '/../src/Savings/SavingsPlanService.php');
if ($openApi === false || $serviceSource === false) {
    throw new RuntimeException('Savings Plan contract sources could not be read');
}

$extractEnum = static function (string $schema, string $openApiSource): array {
    $pattern = '/    ' . preg_quote($schema, '/') . ":\\s*\\n(?:.*\\n)*?        status: \\{ type: string, enum: \\[([^]]+)\\] \\}/";
    if (preg_match($pattern, $openApiSource, $matches) !== 1) {
        throw new RuntimeException('OpenAPI enum not found for ' . $schema);
    }
    return array_values(array_filter(array_map('trim', explode(',', $matches[1])), static fn(string $value): bool => $value !== ''));
};

$extractRuntimeStatuses = static function (string $method, string $serviceSource): array {
    $pattern = '/private function ' . preg_quote($method, '/') . '\\b.*?(?=\\n    private function|\\n})/s';
    if (preg_match($pattern, $serviceSource, $matches) !== 1) {
        throw new RuntimeException('Savings Plan status method not found: ' . $method);
    }
    preg_match_all("/'status'\\s*=>\\s*([^,\\]]+)/", $matches[0], $statusExpressions);
    $statuses = [];
    foreach ($statusExpressions[1] as $expression) {
        preg_match_all("/'([^']+)'/", $expression, $literalMatches);
        $statuses = array_merge($statuses, $literalMatches[1]);
    }
    return array_values(array_unique($statuses));
};

$assertEnumMatchesRuntime = static function (string $schema, string $method) use ($extractEnum, $extractRuntimeStatuses, $openApi, $serviceSource): void {
    $documented = $extractEnum($schema, $openApi);
    $runtime = $extractRuntimeStatuses($method, $serviceSource);
    sort($documented);
    sort($runtime);
    if ($documented !== $runtime) {
        throw new RuntimeException($schema . ' OpenAPI enum does not match runtime statuses: documented [' . implode(', ', $documented) . '], runtime [' . implode(', ', $runtime) . ']');
    }
};

$assertEnumMatchesRuntime('SavingsPlanFundPace', 'fundPace');
$assertEnumMatchesRuntime('SavingsPlanGoalPacingSummary', 'goalPacing');

$initial = $service->getForMonth(1, '2026-07');
if ($initial['has_plan'] || $initial['summary']['saved_amount'] !== '0.00' || $initial['budget']['savings_budget'] !== '1000.00') {
    throw new RuntimeException('initial Savings Plan read failed');
}
if (!in_array($initial['goal_pacing']['status'], $extractEnum('SavingsPlanGoalPacingSummary', $openApi), true)) {
    throw new RuntimeException('unexpected goal pacing status');
}
foreach ($initial['funds'] as $fund) {
    if (!in_array($fund['pace']['status'], $extractEnum('SavingsPlanFundPace', $openApi), true)) {
        throw new RuntimeException('unexpected fund pace status');
    }
}

$service->replaceForMonth(1, '2026-07', ['allocations' => [['fund_id' => 'fund_japan', 'amount' => '900.00']]]);
$pdo->exec("INSERT INTO transactions (user_id, transaction_date, amount, category) VALUES (1, '2026-07-10', '100.00', 'savings')");
$pdo->exec("INSERT INTO fund_entries (user_id, fund_id, entry_date, entry_type, direction, amount, source_type, source_transaction_id) VALUES (1, 1, '2026-07-10', 'contribution', 'in', '100.00', 'transaction', 1)");
$read = $service->getForMonth(1, '2026-07');
if (!$read['has_plan'] || $read['summary']['saved_amount'] !== '100.00' || $read['summary']['transaction_directed_to_funds'] !== '100.00' || $read['funds'][0]['progress_amount'] !== '100.00') {
    throw new RuntimeException('derived Savings Plan progress failed');
}

$failed = false;
try {
    $service->replaceForMonth(1, '2026-07', ['allocations' => [['fund_id' => 'fund_japan', 'amount' => '1000.01']]]);
} catch (HttpException $e) {
    $failed = $e->status === 422;
}
if (!$failed) {
    throw new RuntimeException('over-allocation was not rejected');
}

foreach (['0.00', '-1.00', '1.001', 'abc'] as $invalidAmount) {
    $failed = false;
    try {
        $service->replaceForMonth(1, '2026-07', ['allocations' => [['fund_id' => 'fund_japan', 'amount' => $invalidAmount]]]);
    } catch (HttpException $e) {
        $failed = $e->status === 422;
    }
    if (!$failed) {
        throw new RuntimeException('invalid allocation amount was accepted: ' . $invalidAmount);
    }
}
$service->replaceForMonth(1, '2026-07', ['allocations' => [['fund_id' => 'fund_japan', 'amount' => '0.01']]]);
$service->replaceForMonth(1, '2026-07', ['allocations' => [['fund_id' => 'fund_japan', 'amount' => '460.00']]]);

$pdo->exec("INSERT INTO monthly_closeouts (user_id, month, status) VALUES (1, '2026-07-01', 'closed')");
$failed = false;
try {
    $service->replaceForMonth(1, '2026-07', ['allocations' => []]);
} catch (HttpException $e) {
    $failed = $e->status === 409;
}
if (!$failed) {
    throw new RuntimeException('closed-month edit was not rejected');
}

fwrite(STDOUT, "Savings Plan smoke test passed\n");
