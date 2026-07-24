<?php

declare(strict_types=1);

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Budget\BudgetSettingsResolver;
use App\Controllers\AuthController;
use App\Controllers\MonthOverviewController;
use App\Controllers\MasterApiKeyController;
use App\Controllers\TaxonomyController;
use App\Controllers\TransactionController;
use App\Core\App;
use App\Core\Config;
use App\Auth\AuthService;
use App\Funds\FundBalanceService;
use App\Funds\FundCloseoutIntegrationService;
use App\Funds\FundRepository;
use App\Funds\FundService;
use App\Funds\FundTransactionIntegrationService;
use App\ImportExport\CsvExportService;
use App\ImportExport\CsvImportCommitter;
use App\ImportExport\CsvImportMapper;
use App\ImportExport\CsvImportReader;
use App\ImportExport\DataRunRepository;
use App\ImportExport\TaxonomyImportRepository;
use App\MonthCloseout\MonthCloseoutRepository;
use App\MonthCloseout\MonthCloseoutService;
use App\Monitoring\StructuredLogger;
use App\Overview\MonthOverviewService;
use App\Support\Str;
use App\Security\AuditLogger;

require __DIR__ . '/../src/bootstrap.php';

$jsonResponse = Response::json(['ok' => true], 201);
assertSame(201, $jsonResponse->status, 'json response keeps status');
assertSame('{"ok":true}', $jsonResponse->body, 'json response encodes compact JSON');
assertSame('application/json', $jsonResponse->headers['Content-Type'] ?? null, 'json response sets content type');

$responseWithHeader = $jsonResponse->withHeader('X-Test', 'yes');
assertSame(null, $jsonResponse->headers['X-Test'] ?? null, 'withHeader does not mutate original response');
assertSame('yes', $responseWithHeader->headers['X-Test'] ?? null, 'withHeader returns response with header');

$rawResponse = Response::raw('csv', 202, ['Content-Type' => 'text/csv']);
assertSame(202, $rawResponse->status, 'raw response keeps status');
assertSame('csv', $rawResponse->body, 'raw response keeps body');
assertSame('text/csv', $rawResponse->headers['Content-Type'] ?? null, 'raw response keeps headers');

$streamResponse = Response::stream(static function (): void {
    echo 'streamed';
}, 203, ['Content-Type' => 'text/plain']);
assertSame(203, $streamResponse->status, 'stream response keeps status');
assertSame('', $streamResponse->body, 'stream response has empty buffered body');
assertSame('text/plain', $streamResponse->headers['Content-Type'] ?? null, 'stream response keeps headers');

$request = new Request(
    method: 'POST',
    path: '/api/v1/widgets/abc',
    rawBody: '{"json_value":"from-json"}',
    query: ['page' => '1'],
    cookies: ['sid' => 'session'],
    files: [],
    post: ['json_value' => 'from-post'],
    headers: ['Content-Type' => 'application/json', 'X-Custom-Header' => 'custom']
);

assertSame('application/json', $request->header('content-type'), 'request headers are case-insensitive');
assertSame('custom', $request->header('x-custom-header'), 'request keeps custom headers');
assertSame('from-post', $request->input('json_value'), 'request input prefers form fields over JSON');
assertSame(['json_value' => 'from-json'], $request->json(), 'request decodes JSON body');

$invalidJsonRequest = new Request(
    method: 'POST',
    path: '/broken',
    rawBody: 'not-json',
    query: [],
    cookies: [],
    files: [],
    post: [],
    headers: []
);
expectHttpException(
    fn() => $invalidJsonRequest->json(),
    422,
    'VALIDATION_ERROR',
    'invalid JSON raises validation error'
);

$router = new Router();
$router->add('GET', '/widgets/{widget_id}', static function (Request $request, array $params): Response {
    return Response::json([
        'method' => $request->method,
        'widget_id' => $params['widget_id'] ?? null,
    ]);
});
$router->add('GET', '/ping', static fn(Request $request): Response => Response::json(['pong' => true]));

$routed = $router->dispatch(new Request('GET', '/widgets/abc-123', '', [], [], [], [], []));
assertSame(200, $routed->status, 'router returns matched response');
assertSame('{"method":"GET","widget_id":"abc-123"}', $routed->body, 'router passes named path parameters');

$ping = $router->dispatch(new Request('GET', '/ping', '', [], [], [], [], []));
assertSame('{"pong":true}', $ping->body, 'router supports handlers without params');

expectHttpException(
    fn() => $router->dispatch(new Request('POST', '/ping', '', [], [], [], [], [])),
    404,
    'NOT_FOUND',
    'router rejects unmatched method'
);

$randomId = Str::randomId('tst');
assertMatches('/^tst_[a-f0-9]{20}$/', $randomId, 'randomId includes prefix and 10 random bytes');
assertMatches('/^[a-f0-9]{16}$/', Str::randomHex(8), 'randomHex emits requested byte length');
assertSame(hash('sha256', 'budget'), Str::hashSha256('budget'), 'hashSha256 matches PHP hash');
assertMatches('/^\d{6}$/', Str::randomNumericCode(), 'randomNumericCode defaults to six digits');
assertMatches('/^\d{8}$/', Str::randomNumericCode(8), 'randomNumericCode supports custom lengths');

assertSame('2026-06', BudgetSettingsResolver::normalizeMonth('2026-06'), 'budget settings resolver accepts valid month');
expectHttpException(
    fn() => BudgetSettingsResolver::normalizeMonth('2026-6'),
    422,
    'VALIDATION_ERROR',
    'budget settings resolver rejects malformed month'
);
expectHttpException(
    fn() => BudgetSettingsResolver::normalizeMonth('2026-13'),
    422,
    'VALIDATION_ERROR',
    'budget settings resolver rejects invalid month'
);

$budgetPdo = new PDO('sqlite::memory:');
$budgetPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$budgetPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$budgetPdo->exec('CREATE TABLE budget_settings_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    effective_month TEXT NOT NULL,
    monthly_income TEXT NOT NULL,
    income_source_type TEXT NOT NULL,
    primary_monthly_income TEXT NULL,
    primary_hourly_rate TEXT NULL,
    primary_weekly_hours TEXT NULL,
    side_income_type TEXT NOT NULL,
    side_income_label TEXT NULL,
    side_monthly_income TEXT NULL,
    side_hourly_rate TEXT NULL,
    side_weekly_hours TEXT NULL,
    allocation_mode TEXT NOT NULL,
    needs_percent TEXT NULL,
    wants_percent TEXT NULL,
    savings_percent TEXT NULL,
    needs_amount TEXT NULL,
    wants_amount TEXT NULL,
    savings_amount TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)');
$budgetResolver = new BudgetSettingsResolver($budgetPdo);
$insertVersion = static function (
    int $userId,
    string $effectiveMonth,
    string $monthlyIncome,
    string $allocationMode,
    ?string $needsPercent,
    ?string $wantsPercent,
    ?string $savingsPercent,
    ?string $needsAmount,
    ?string $wantsAmount,
    ?string $savingsAmount,
    string $createdAt,
    string $updatedAt
) use ($budgetPdo): void {
    $stmt = $budgetPdo->prepare('
        INSERT INTO budget_settings_versions (
            user_id, effective_month, monthly_income, income_source_type,
            primary_monthly_income, primary_hourly_rate, primary_weekly_hours,
            side_income_type, side_income_label, side_monthly_income, side_hourly_rate, side_weekly_hours,
            allocation_mode, needs_percent, wants_percent, savings_percent, needs_amount, wants_amount, savings_amount,
            created_at, updated_at
        ) VALUES (
            :user_id, :effective_month, :monthly_income, :income_source_type,
            :primary_monthly_income, :primary_hourly_rate, :primary_weekly_hours,
            :side_income_type, :side_income_label, :side_monthly_income, :side_hourly_rate, :side_weekly_hours,
            :allocation_mode, :needs_percent, :wants_percent, :savings_percent, :needs_amount, :wants_amount, :savings_amount,
            :created_at, :updated_at
        )
    ');
    $stmt->execute([
        ':user_id' => $userId,
        ':effective_month' => $effectiveMonth,
        ':monthly_income' => $monthlyIncome,
        ':income_source_type' => 'monthly',
        ':primary_monthly_income' => $monthlyIncome,
        ':primary_hourly_rate' => null,
        ':primary_weekly_hours' => null,
        ':side_income_type' => 'none',
        ':side_income_label' => null,
        ':side_monthly_income' => null,
        ':side_hourly_rate' => null,
        ':side_weekly_hours' => null,
        ':allocation_mode' => $allocationMode,
        ':needs_percent' => $needsPercent,
        ':wants_percent' => $wantsPercent,
        ':savings_percent' => $savingsPercent,
        ':needs_amount' => $needsAmount,
        ':wants_amount' => $wantsAmount,
        ':savings_amount' => $savingsAmount,
        ':created_at' => $createdAt,
        ':updated_at' => $updatedAt,
    ]);
};

assertSame([], $budgetResolver->getBudgetSettingsVersions(1), 'budget settings versions returns an empty list for new users');

$insertVersion(
    1,
    '2025-11-01',
    '6200.00',
    'percent',
    '50.00',
    '30.00',
    '20.00',
    null,
    null,
    null,
    '2026-06-01T10:00:00Z',
    '2026-06-01T10:00:00Z'
);
$versions = $budgetResolver->getBudgetSettingsVersions(1);
assertSame(1, count($versions), 'budget settings versions returns one version');
assertSame('2025-11', $versions[0]['effective_month'], 'budget settings versions formats effective month as YYYY-MM');
assertSame(null, $versions[0]['applies_until_month'], 'single budget settings version has no applies_until_month');
assertSame('3100.00', $versions[0]['resolved_amounts']['needs'], 'percent mode resolves needs amount');
assertSame('1860.00', $versions[0]['resolved_amounts']['wants'], 'percent mode resolves wants amount');
assertSame('1240.00', $versions[0]['resolved_amounts']['savings'], 'percent mode resolves savings amount');
assertSame('2026-06-01T10:00:00Z', $versions[0]['created_at'], 'budget settings versions keeps created_at');

$insertVersion(
    1,
    '2025-10-01',
    '6200.00',
    'percent',
    '50.00',
    '30.00',
    '20.00',
    null,
    null,
    null,
    '2026-06-01T09:00:00Z',
    '2026-06-01T09:00:00Z'
);
$insertVersion(
    1,
    '2025-12-01',
    '6200.00',
    'amount',
    null,
    null,
    null,
    '3100.00',
    '1860.00',
    '1240.00',
    '2026-06-01T11:00:00Z',
    '2026-06-01T11:00:00Z'
);
$insertVersion(
    2,
    '2025-09-01',
    '5000.00',
    'percent',
    '60.00',
    '20.00',
    '20.00',
    null,
    null,
    null,
    '2026-06-01T08:00:00Z',
    '2026-06-01T08:00:00Z'
);
$versions = $budgetResolver->getBudgetSettingsVersions(1);
assertSame(3, count($versions), 'budget settings versions keeps user scope');
assertSame('2025-10', $versions[0]['effective_month'], 'historical insert sorts before later versions');
assertSame('2025-10', $versions[0]['applies_until_month'], 'historical insert ends the earlier version the month before the next one starts');
assertSame('2025-11', $versions[1]['effective_month'], 'second version remains the next month');
assertSame('2025-11', $versions[1]['applies_until_month'], 'next version ends before the following version');
assertSame('2025-12', $versions[2]['effective_month'], 'last version sorts last');
assertSame(null, $versions[2]['applies_until_month'], 'last version keeps null applies_until_month');
assertSame('3100.00', $versions[2]['resolved_amounts']['needs'], 'amount mode resolves stored needs amount');
assertSame('1860.00', $versions[2]['resolved_amounts']['wants'], 'amount mode resolves stored wants amount');
assertSame('1240.00', $versions[2]['resolved_amounts']['savings'], 'amount mode resolves stored savings amount');
assertSame('2025-09', $budgetResolver->getBudgetSettingsVersions(2)[0]['effective_month'], 'budget settings versions isolates other users');

$overviewPdo = $budgetPdo;
$overviewPdo->exec('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    display_name TEXT NOT NULL,
    avatar_url TEXT NULL,
    user_preferences TEXT NOT NULL,
    auth_provider TEXT NOT NULL,
    email_verified INTEGER NOT NULL DEFAULT 1,
    role TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
)');
$overviewPdo->exec('CREATE TABLE master_api_keys (
    key_id TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    key_prefix TEXT NOT NULL,
    key_hash TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    expires_at TEXT NULL,
    revoked_at TEXT NULL,
    last_used_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$overviewPdo->exec('CREATE TABLE tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    icon_key TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    deleted_at TEXT NULL,
    updated_at TEXT NOT NULL
)');
$overviewPdo->exec('CREATE TABLE cards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    is_favorite INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    deleted_at TEXT NULL,
    updated_at TEXT NOT NULL
)');
$overviewPdo->exec('CREATE TABLE contexts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    icon_key TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    deleted_at TEXT NULL,
    updated_at TEXT NOT NULL
)');
$overviewPdo->exec('CREATE TABLE transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    transaction_date TEXT NOT NULL,
    expense TEXT NOT NULL,
    amount TEXT NOT NULL,
    category TEXT NOT NULL,
    is_split INTEGER NOT NULL DEFAULT 0,
    notes TEXT NULL,
    tag_id INTEGER NOT NULL,
    card_id INTEGER NULL,
    context_id INTEGER NULL,
    source TEXT NOT NULL DEFAULT "manual",
    recurring_expense_id INTEGER NULL,
    deleted_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)');
$overviewPdo->exec('CREATE TABLE recurring_expenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    expense TEXT NOT NULL,
    amount TEXT NOT NULL,
    category TEXT NOT NULL,
    tag_id INTEGER NOT NULL,
    card_id INTEGER NULL,
    billing_type TEXT NOT NULL,
    billing_day INTEGER NULL,
    starts_month TEXT NOT NULL,
    ends_month TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    deleted_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$overviewPdo->exec('CREATE TABLE recurring_expense_occurrences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    recurring_expense_id INTEGER NOT NULL,
    occurrence_month TEXT NOT NULL,
    due_date TEXT NOT NULL,
    transaction_id INTEGER NULL
)');

$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$currentMonth = $nowUtc->format('Y-m');
$pastMonth = $nowUtc->modify('-1 month')->format('Y-m');
$futureMonth = $nowUtc->modify('+1 month')->format('Y-m');
$pastInheritedMonth = $nowUtc->modify('-5 month')->format('Y-m');
$noBudgetMonth = '2025-09';

$overviewPdo->prepare('INSERT INTO users (id, email, display_name, avatar_url, user_preferences, auth_provider, email_verified, role, is_active, created_at) VALUES (1, :email, :display_name, NULL, :prefs, "password", 1, "member", 1, :created_at)')
    ->execute([
        ':email' => 'owner@example.com',
        ':display_name' => 'Owner',
        ':prefs' => '{"appearance":{"theme":"system"}}',
        ':created_at' => '2026-06-01T00:00:00Z',
    ]);
$overviewPdo->prepare('INSERT INTO users (id, email, display_name, avatar_url, user_preferences, auth_provider, email_verified, role, is_active, created_at) VALUES (2, :email, :display_name, NULL, :prefs, "password", 1, "member", 1, :created_at)')
    ->execute([
        ':email' => 'other@example.com',
        ':display_name' => 'Other',
        ':prefs' => '{"appearance":{"theme":"system"}}',
        ':created_at' => '2026-06-01T00:00:00Z',
    ]);

$apiKey = 'bgtm_live_testkey_abcdef1234567890';
$overviewPdo->prepare('INSERT INTO master_api_keys (key_id, user_id, name, key_prefix, key_hash, is_active, expires_at, revoked_at, last_used_at) VALUES (:key_id, 1, "test key", "bgtm_live_testkey", :key_hash, 1, NULL, NULL, NULL)')
    ->execute([
        ':key_id' => 'mak_test',
        ':key_hash' => Str::hashSha256($apiKey),
    ]);
$overviewPdo->prepare('INSERT INTO contexts (user_id, name, icon_key, is_active, deleted_at, updated_at) VALUES (1, :name, :icon_key, 1, NULL, :updated_at)')
    ->execute([
        ':name' => 'Chicago 2/26',
        ':icon_key' => 'map_pinned',
        ':updated_at' => '2026-06-01T00:00:00Z',
    ]);
$overviewContextId = (int) $overviewPdo->lastInsertId();

$insertTag = static function (int $userId, string $name, string $iconKey) use ($overviewPdo): int {
    $stmt = $overviewPdo->prepare('INSERT INTO tags (user_id, name, icon_key, is_active, deleted_at, updated_at) VALUES (:user_id, :name, :icon_key, 1, NULL, :updated_at)');
    $stmt->execute([
        ':user_id' => $userId,
        ':name' => $name,
        ':icon_key' => $iconKey,
        ':updated_at' => '2026-06-01T00:00:00Z',
    ]);

    return (int) $overviewPdo->lastInsertId();
};

$insertTransaction = static function (
    int $userId,
    string $date,
    string $expense,
    string $amount,
    string $category,
    int $tagId,
    ?int $cardId = null,
    string $source = 'manual',
    ?int $contextId = null
) use ($overviewPdo): int {
    $stmt = $overviewPdo->prepare('INSERT INTO transactions (user_id, transaction_date, expense, amount, category, is_split, notes, tag_id, card_id, context_id, source, recurring_expense_id, deleted_at, created_at, updated_at) VALUES (:user_id, :transaction_date, :expense, :amount, :category, 0, NULL, :tag_id, :card_id, :context_id, :source, NULL, NULL, :created_at, :updated_at)');
    $stmt->execute([
        ':user_id' => $userId,
        ':transaction_date' => $date,
        ':expense' => $expense,
        ':amount' => $amount,
        ':category' => $category,
        ':tag_id' => $tagId,
        ':card_id' => $cardId,
        ':context_id' => $contextId,
        ':source' => $source,
        ':created_at' => $date . 'T12:00:00Z',
        ':updated_at' => $date . 'T12:00:00Z',
    ]);

    return (int) $overviewPdo->lastInsertId();
};

$insertRecurringExpense = static function (
    int $userId,
    string $expense,
    string $amount,
    string $category,
    int $tagId,
    ?int $cardId,
    string $billingType,
    ?int $billingDay,
    string $startsMonth,
    ?string $endsMonth
) use ($overviewPdo): int {
    $stmt = $overviewPdo->prepare('INSERT INTO recurring_expenses (user_id, expense, amount, category, tag_id, card_id, billing_type, billing_day, starts_month, ends_month, is_active, deleted_at, created_at, updated_at) VALUES (:user_id, :expense, :amount, :category, :tag_id, :card_id, :billing_type, :billing_day, :starts_month, :ends_month, 1, NULL, :created_at, :updated_at)');
    $stmt->execute([
        ':user_id' => $userId,
        ':expense' => $expense,
        ':amount' => $amount,
        ':category' => $category,
        ':tag_id' => $tagId,
        ':card_id' => $cardId,
        ':billing_type' => $billingType,
        ':billing_day' => $billingDay,
        ':starts_month' => $startsMonth . '-01',
        ':ends_month' => $endsMonth === null ? null : $endsMonth . '-01',
        ':created_at' => '2026-06-01T00:00:00Z',
        ':updated_at' => '2026-06-01T00:00:00Z',
    ]);

    return (int) $overviewPdo->lastInsertId();
};

$needsTag = $insertTag(1, 'Needs', 'home');
$wantsTag = $insertTag(1, 'Wants', 'shopping_cart');
$savingsTag = $insertTag(1, 'Savings', 'savings');
$unusedTag = $insertTag(1, 'Unused', 'tag');
$otherUserTag = $insertTag(2, 'Needs', 'home');
$pastExtraTags = [];
foreach (range(1, 9) as $index) {
    $pastExtraTags[] = $insertTag(1, 'Past Extra ' . $index, 'tag');
}

$insertVersion(1, $pastMonth . '-01', '1000.00', 'amount', null, null, null, '400.00', '300.00', '300.00', '2026-06-01T00:00:00Z', '2026-06-01T00:00:00Z');
$insertVersion(1, $currentMonth . '-01', '1000.00', 'amount', null, null, null, '500.00', '300.00', '200.00', '2026-06-01T00:00:00Z', '2026-06-01T00:00:00Z');

$currentTx1 = $insertTransaction(1, $currentMonth . '-01', 'Groceries', '400.00', 'needs', $needsTag);
$currentTx2 = $insertTransaction(1, $currentMonth . '-02', 'Utilities', '100.00', 'needs', $needsTag);
$currentTx3 = $insertTransaction(1, $currentMonth . '-03', 'Dining Out', '250.00', 'wants', $wantsTag, null, 'manual', $overviewContextId);
$currentTx4 = $insertTransaction(1, $currentMonth . '-04', 'Shopping', '100.00', 'wants', $wantsTag);
$currentTx5 = $insertTransaction(1, $currentMonth . '-05', 'Emergency Fund', '50.00', 'savings', $savingsTag);
$currentTx6 = $insertTransaction(1, $currentMonth . '-06', 'Rainy Day', '10.00', 'savings', $savingsTag);
$deletedTx = $insertTransaction(1, $currentMonth . '-07', 'Deleted Coffee', '999.00', 'wants', $wantsTag);
$overviewPdo->prepare('UPDATE transactions SET deleted_at = :deleted_at WHERE id = :id AND user_id = :user_id')
    ->execute([
        ':deleted_at' => '2026-06-01T00:00:00Z',
        ':id' => $deletedTx,
        ':user_id' => 1,
    ]);
$insertTransaction(2, $currentMonth . '-06', 'Other User Spend', '9999.00', 'needs', $otherUserTag);
foreach ($pastExtraTags as $index => $tagId) {
    $day = sprintf('%02d', $index + 1);
    $amount = number_format((float) ($index + 1), 2, '.', '');
    $insertTransaction(1, $pastMonth . '-' . $day, 'Past Extra Transaction ' . ($index + 1), $amount, 'wants', $tagId);
}

$currentMonthStart = DateTimeImmutable::createFromFormat('Y-m-d', $currentMonth . '-01', new DateTimeZone('UTC'));
assert($currentMonthStart !== false);
$currentMonthDays = (int) $currentMonthStart->modify('last day of this month')->format('d');
$currentDaysElapsed = (int) $nowUtc->format('j');
$futureCurrentMonthDate = null;
if ($currentDaysElapsed < $currentMonthDays) {
    $futureCurrentMonthDate = sprintf('%s-%02d', $currentMonth, $currentDaysElapsed + 1);
    $insertTransaction(1, $futureCurrentMonthDate, 'Future Scheduled Transfer', '0.00', 'savings', $savingsTag);
}

$recurringGenerated = $insertRecurringExpense(1, 'Rent', '25.00', 'needs', $needsTag, null, 'day_of_month', 1, $currentMonth, null);
$recurringPending = $insertRecurringExpense(1, 'Subscription', '75.00', 'wants', $wantsTag, null, 'day_of_month', 15, $currentMonth, null);
$overviewPdo->prepare('INSERT INTO recurring_expense_occurrences (user_id, recurring_expense_id, occurrence_month, due_date, transaction_id) VALUES (1, :recurring_expense_id, :occurrence_month, :due_date, :transaction_id)')
    ->execute([
        ':recurring_expense_id' => $recurringGenerated,
        ':occurrence_month' => $currentMonth . '-01',
        ':due_date' => $currentMonth . '-04',
        ':transaction_id' => $currentTx4,
    ]);

$overviewConfig = Config::load(dirname(__DIR__));
$overviewAuth = new AuthService($overviewPdo, $overviewConfig);
$monthOverviewService = new MonthOverviewService($overviewPdo, $budgetResolver);
$monthOverviewController = new MonthOverviewController($overviewAuth, $monthOverviewService);

$currentOverviewRequest = new Request(
    method: 'GET',
    path: '/api/v1/me/months/' . $currentMonth . '/overview',
    rawBody: '',
    query: [],
    cookies: [],
    files: [],
    post: [],
    headers: ['X-API-Key' => $apiKey]
);
$currentOverview = json_decode($monthOverviewController->overview($currentOverviewRequest, ['month' => $currentMonth])->body, true);
assertSame($currentMonth, $currentOverview['month'], 'month overview returns the requested month');
assertSame($currentMonth, $currentOverview['budget']['resolved_effective_month'], 'month overview resolves exact budget month');
assertSame(true, $currentOverview['budget']['is_exact_match'], 'month overview reports exact budget month');
assertSame(true, $currentOverview['budget']['has_budget'], 'month overview reports that a budget exists');
assertSame('1000.00', $currentOverview['budget']['monthly_income'], 'month overview reports monthly income');
assertSame('1000.00', $currentOverview['summary']['total_budget'], 'month overview totals budget');
assertSame('910.00', $currentOverview['summary']['total_spent'], 'month overview totals spend');
assertSame('90.00', $currentOverview['summary']['left_this_month'], 'month overview totals remaining');
assertSame('91.00', $currentOverview['summary']['percent_spent'], 'month overview totals percent spent');
assertSame('current', $currentOverview['month_progress']['status'], 'month overview current month status');
$overviewContextTransaction = array_values(array_filter(
    $currentOverview['recent_transactions'],
    static fn(array $item): bool => $item['id'] === (string) $currentTx3
))[0] ?? null;
assertSame('Chicago 2/26', $overviewContextTransaction['context']['name'] ?? null, 'month overview recent transactions include context');
$currentDaysRemaining = $currentMonthDays - $currentDaysElapsed;
$currentPercentElapsed = number_format(($currentDaysElapsed / $currentMonthDays) * 100.0, 2, '.', '');
$currentDailyAvailable = number_format(90.0 / max($currentDaysRemaining, 1), 2, '.', '');
$currentProjected = number_format(910.0 / ($currentDaysElapsed / $currentMonthDays), 2, '.', '');
assertSame($currentMonthDays, $currentOverview['month_progress']['days_in_month'], 'month overview current month days in month');
assertSame($currentDaysElapsed, $currentOverview['month_progress']['day_of_month'], 'month overview current month day of month');
assertSame($currentDaysElapsed, $currentOverview['month_progress']['days_elapsed'], 'month overview current month days elapsed');
assertSame($currentDaysRemaining, $currentOverview['month_progress']['days_remaining'], 'month overview current month days remaining');
assertSame($currentPercentElapsed, $currentOverview['month_progress']['percent_elapsed'], 'month overview current month percent elapsed');
assertSame($currentDailyAvailable, $currentOverview['month_progress']['daily_available_remaining'], 'month overview current month daily available remaining');
assertSame($currentProjected, $currentOverview['month_progress']['projected_month_end_spend'], 'month overview current month projected month end spend');
assertSame('needs', $currentOverview['categories'][0]['category'], 'month overview returns needs category first');
assertSame('near', $currentOverview['categories'][0]['status'], 'month overview marks needs as near');
assertSame('wants', $currentOverview['categories'][1]['category'], 'month overview returns wants category second');
assertSame('over', $currentOverview['categories'][1]['status'], 'month overview marks wants as over');
assertSame('savings', $currentOverview['categories'][2]['category'], 'month overview returns savings category third');
assertSame('under', $currentOverview['categories'][2]['status'], 'month overview marks savings as under');
assertSame('100.00', $currentOverview['categories'][0]['percent_used'], 'month overview calculates needs percent');
assertSame('116.67', $currentOverview['categories'][1]['percent_used'], 'month overview calculates wants percent');
assertSame('30.00', $currentOverview['categories'][2]['percent_used'], 'month overview calculates savings percent');
assertSame('100.00', $currentOverview['recurring']['committed_total'], 'month overview recurring committed total');
assertSame('25.00', $currentOverview['recurring']['generated_total'], 'month overview recurring generated total');
assertSame('75.00', $currentOverview['recurring']['upcoming_total'], 'month overview recurring upcoming total');
assertSame(2, $currentOverview['recurring']['items_count'], 'month overview recurring items count');
assertSame(1, $currentOverview['recurring']['generated_count'], 'month overview recurring generated count');
assertSame(1, $currentOverview['recurring']['upcoming_count'], 'month overview recurring upcoming count');
assertSame(3, count($currentOverview['tags']), 'month overview returns top spending tags');
assertSame('needs', $currentOverview['tags'][0]['tag_name'] !== '' ? 'needs' : 'needs', 'month overview tags include needs');
assertSame('Needs', $currentOverview['tags'][0]['tag_name'], 'month overview tags sort by spend descending');
assertSame('Wants', $currentOverview['tags'][1]['tag_name'], 'month overview tags include wants second');
assertSame('Savings', $currentOverview['tags'][2]['tag_name'], 'month overview tags include savings third');
assertSame('54.95', $currentOverview['tags'][0]['percent_of_monthly_spend'], 'month overview tag percent uses monthly spend');
assertSame('38.46', $currentOverview['tags'][1]['percent_of_monthly_spend'], 'month overview tag percent uses monthly spend');
assertSame('6.59', $currentOverview['tags'][2]['percent_of_monthly_spend'], 'month overview tag percent uses monthly spend');
assertSame(5, count($currentOverview['recent_transactions']), 'month overview limits recent transactions');
assertSame($currentMonth . '-06', $currentOverview['recent_transactions'][0]['date'], 'month overview sorts recent transactions descending');
assertSame($currentMonth . '-02', $currentOverview['recent_transactions'][4]['date'], 'month overview keeps the fifth most recent transaction');
assertSame((string) $recurringGenerated, $currentOverview['recent_transactions'][2]['recurring_expense_id'], 'month overview exposes recurring transaction linkage');
foreach ($currentOverview['recent_transactions'] as $recentTransaction) {
    assertSame(true, $recentTransaction['date'] <= $nowUtc->format('Y-m-d'), 'month overview current month recent transactions stop at today');
}
if ($futureCurrentMonthDate !== null) {
    assertSame(false, in_array($futureCurrentMonthDate, array_column($currentOverview['recent_transactions'], 'date'), true), 'month overview excludes future-dated current month transactions from recent list');
}
assertSame('month_pace', $currentOverview['status_cards'][0]['id'], 'month overview returns month pace first');
assertSame('largest_category', $currentOverview['status_cards'][1]['id'], 'month overview returns largest category second');
assertSame('recurring', $currentOverview['status_cards'][2]['id'], 'month overview returns recurring third');
assertSame('budget_source', $currentOverview['status_cards'][3]['id'], 'month overview returns budget source fourth');

$percentSpent = 91.00;
$percentElapsed = (float) $currentPercentElapsed;
$paceDiff = $percentSpent - $percentElapsed;
$expectedPaceTone = $paceDiff <= 5.0 ? 'good' : ($paceDiff <= 15.0 ? 'neutral' : ($paceDiff <= 30.0 ? 'warning' : 'danger'));
$expectedPaceTitle = match ($expectedPaceTone) {
    'good' => 'On pace',
    'neutral' => 'Slightly ahead',
    'warning' => 'Behind pace',
    default => 'Off pace',
};
assertSame($expectedPaceTone, $currentOverview['status_cards'][0]['tone'], 'month overview month pace tone is deterministic');
assertSame($expectedPaceTitle, $currentOverview['status_cards'][0]['title'], 'month overview month pace title is deterministic');
assertSame('Exact budget', $currentOverview['status_cards'][3]['title'], 'month overview exact budget source title');
assertSame('Exact budget', $currentOverview['status_cards'][3]['value'], 'month overview exact budget source value');

$noBudgetRequest = new Request(
    method: 'GET',
    path: '/api/v1/me/months/' . $noBudgetMonth . '/overview',
    rawBody: '',
    query: [],
    cookies: [],
    files: [],
    post: [],
    headers: ['X-API-Key' => $apiKey]
);
$noBudgetOverview = json_decode($monthOverviewController->overview($noBudgetRequest, ['month' => $noBudgetMonth])->body, true);
assertSame(false, $noBudgetOverview['budget']['has_budget'], 'month overview reports no budget exists');
assertSame(null, $noBudgetOverview['budget']['resolved_effective_month'], 'month overview keeps no budget resolved month null');
assertSame(false, $noBudgetOverview['budget']['is_exact_match'], 'month overview reports no exact match when no budget exists');
assertSame(null, $noBudgetOverview['budget']['monthly_income'], 'month overview returns null monthly income when no budget exists');
assertSame(null, $noBudgetOverview['summary']['total_budget'], 'month overview returns null total budget when no budget exists');
assertSame(null, $noBudgetOverview['summary']['left_this_month'], 'month overview returns null left this month when no budget exists');
assertSame(null, $noBudgetOverview['summary']['percent_spent'], 'month overview returns null percent spent when no budget exists');
assertSame('0.00', $noBudgetOverview['summary']['total_spent'], 'month overview zeroes total spent when no budget exists');
assertSame('past', $noBudgetOverview['month_progress']['status'], 'month overview marks prior no-budget month as past');
assertSame(0, $noBudgetOverview['month_progress']['days_remaining'], 'month overview past no-budget month days remaining are zero');
assertSame(null, $noBudgetOverview['month_progress']['daily_available_remaining'], 'month overview past no-budget month has no daily availability');
assertSame(null, $noBudgetOverview['month_progress']['projected_month_end_spend'], 'month overview past no-budget month has no projection');
assertSame('No budget set', $noBudgetOverview['status_cards'][0]['title'], 'month overview explains missing budget');
assertSame('no_budget', $noBudgetOverview['status_cards'][0]['id'], 'month overview explains missing budget');
assertSame(0, count($noBudgetOverview['tags']), 'month overview excludes zero-spend tags');
assertSame(3, count($noBudgetOverview['categories']), 'month overview always returns three categories');
assertSame('under', $noBudgetOverview['categories'][0]['status'], 'month overview no-budget categories default to under');

$futureOverviewRequest = new Request(
    method: 'GET',
    path: '/api/v1/me/months/' . $futureMonth . '/overview',
    rawBody: '',
    query: [],
    cookies: [],
    files: [],
    post: [],
    headers: ['X-API-Key' => $apiKey]
);
$futureOverview = json_decode($monthOverviewController->overview($futureOverviewRequest, ['month' => $futureMonth])->body, true);
assertSame(true, $futureOverview['budget']['has_budget'], 'month overview reports inherited budget month has budget');
assertSame(false, $futureOverview['budget']['is_exact_match'], 'month overview reports inherited budget month');
assertSame($currentMonth, $futureOverview['budget']['resolved_effective_month'], 'month overview resolves inherited budget month');
assertSame('1000.00', $futureOverview['summary']['total_budget'], 'month overview inherited future month budget');
assertSame('0.00', $futureOverview['summary']['total_spent'], 'month overview has no transactions for future month');
assertSame('1000.00', $futureOverview['summary']['left_this_month'], 'month overview future month left this month equals total budget');
assertSame('0.00', $futureOverview['summary']['percent_spent'], 'month overview future month percent spent is zero');
assertSame('future', $futureOverview['month_progress']['status'], 'month overview future month status is future');
assertSame(0, $futureOverview['month_progress']['day_of_month'], 'month overview future month day of month is zero');
assertSame(0, $futureOverview['month_progress']['days_elapsed'], 'month overview future month days elapsed is zero');
assertSame((int) DateTimeImmutable::createFromFormat('Y-m-d', $futureMonth . '-01', new DateTimeZone('UTC'))->modify('last day of this month')->format('d'), $futureOverview['month_progress']['days_remaining'], 'month overview future month days remaining equals days in month');
assertSame('0.00', $futureOverview['month_progress']['percent_elapsed'], 'month overview future month percent elapsed is zero');
assertSame(null, $futureOverview['month_progress']['daily_available_remaining'], 'month overview future month has no daily availability');
assertSame(null, $futureOverview['month_progress']['projected_month_end_spend'], 'month overview future month has no projection');
assertSame('Using budget from ' . (DateTimeImmutable::createFromFormat('Y-m', $currentMonth, new DateTimeZone('UTC'))->format('F Y')), $futureOverview['status_cards'][2]['value'], 'month overview future month budget source value');
assertSame('Inherited budget', $futureOverview['status_cards'][2]['title'], 'month overview future month budget source is inherited');

$pastOverviewRequest = new Request(
    method: 'GET',
    path: '/api/v1/me/months/' . $pastMonth . '/overview',
    rawBody: '',
    query: [],
    cookies: [],
    files: [],
    post: [],
    headers: ['X-API-Key' => $apiKey]
);
$pastOverview = json_decode($monthOverviewController->overview($pastOverviewRequest, ['month' => $pastMonth])->body, true);
$pastMonthStart = DateTimeImmutable::createFromFormat('Y-m-d', $pastMonth . '-01', new DateTimeZone('UTC'));
assert($pastMonthStart !== false);
$pastMonthDays = (int) $pastMonthStart->modify('last day of this month')->format('d');
assertSame(true, $pastOverview['budget']['has_budget'], 'month overview past month has budget');
assertSame(true, $pastOverview['budget']['is_exact_match'], 'month overview past month exact budget');
assertSame($pastMonth, $pastOverview['budget']['resolved_effective_month'], 'month overview past month resolved exact month');
assertSame(9, count($pastOverview['tags']), 'month overview past month returns all tags instead of truncating to eight');
assertSame('past', $pastOverview['month_progress']['status'], 'month overview past month status is past');
assertSame($pastMonthDays, $pastOverview['month_progress']['days_in_month'], 'month overview past month days in month');
assertSame($pastMonthDays, $pastOverview['month_progress']['days_elapsed'], 'month overview past month days elapsed');
assertSame(0, $pastOverview['month_progress']['days_remaining'], 'month overview past month days remaining');
assertSame('100.00', $pastOverview['month_progress']['percent_elapsed'], 'month overview past month percent elapsed');
assertSame('Exact budget', $pastOverview['status_cards'][2]['title'], 'month overview past month budget source title');
assertSame('Exact budget', $pastOverview['status_cards'][2]['value'], 'month overview past month budget source value');

$pastInheritedOverviewRequest = new Request(
    method: 'GET',
    path: '/api/v1/me/months/' . $pastInheritedMonth . '/overview',
    rawBody: '',
    query: [],
    cookies: [],
    files: [],
    post: [],
    headers: ['X-API-Key' => $apiKey]
);
$pastInheritedOverview = json_decode($monthOverviewController->overview($pastInheritedOverviewRequest, ['month' => $pastInheritedMonth])->body, true);
assertSame(true, $pastInheritedOverview['budget']['has_budget'], 'month overview inherited past month has budget');
assertSame(false, $pastInheritedOverview['budget']['is_exact_match'], 'month overview inherited past month is inherited');
assertSame('2025-12', $pastInheritedOverview['budget']['resolved_effective_month'], 'month overview inherited past month resolves to most recent budget');
assertSame('past', $pastInheritedOverview['month_progress']['status'], 'month overview inherited past month is past');
assertSame('6200.00', $pastInheritedOverview['summary']['total_budget'], 'month overview inherited past month total budget');
assertSame('6200.00', $pastInheritedOverview['summary']['left_this_month'], 'month overview inherited past month left this month');
assertSame('0.00', $pastInheritedOverview['summary']['percent_spent'], 'month overview inherited past month percent spent');
assertSame('Inherited budget', $pastInheritedOverview['status_cards'][2]['title'], 'month overview inherited past month budget source title');

$csvImportMapper = new CsvImportMapper();
$csvExportReflection = new ReflectionClass(CsvExportService::class);
$csvExportService = $csvExportReflection->newInstanceWithoutConstructor();
$csvCell = $csvExportReflection->getMethod('csvCell');
assertSame("'=SUM(A1:A2)", $csvCell->invoke($csvExportService, '=SUM(A1:A2)'), 'csv export escapes equals formulas');
assertSame("'+cmd", $csvCell->invoke($csvExportService, '+cmd'), 'csv export escapes plus formulas');
assertSame("'-cmd", $csvCell->invoke($csvExportService, '-cmd'), 'csv export escapes minus formulas');
assertSame("'@cmd", $csvCell->invoke($csvExportService, '@cmd'), 'csv export escapes at formulas');
assertSame("' =cmd", $csvCell->invoke($csvExportService, ' =cmd'), 'csv export escapes formulas after leading whitespace');
assertSame('Groceries', $csvCell->invoke($csvExportService, 'Groceries'), 'csv export leaves normal cells unchanged');
$dataRunReflection = new ReflectionClass(DataRunRepository::class);
$dataRunRepository = $dataRunReflection->newInstanceWithoutConstructor();
$clampedDataRunsLimit = $dataRunReflection->getMethod('clampedDataRunsLimit');
assertSame(50, $clampedDataRunsLimit->invoke($dataRunRepository, null), 'data runs limit defaults to 50');
assertSame(1, $clampedDataRunsLimit->invoke($dataRunRepository, '0'), 'data runs limit clamps minimum');
assertSame(100, $clampedDataRunsLimit->invoke($dataRunRepository, '500'), 'data runs limit clamps maximum');
assertSame(25, $clampedDataRunsLimit->invoke($dataRunRepository, '25'), 'data runs limit accepts in-range values');
$dataRunItem = $dataRunReflection->getMethod('dataRunItem');
$importRunItem = $dataRunItem->invoke($dataRunRepository, [
    'id' => 'import_123',
    'type' => 'import',
    'status' => 'partial',
    'created_at' => '2026-05-29 12:00:00',
    'source_filename' => 'transactions.csv',
    'date_from' => null,
    'date_to' => null,
    'total_rows' => '120',
    'valid_rows' => '110',
    'imported_rows' => '108',
    'duplicate_rows' => '2',
    'invalid_rows' => '10',
    'skipped_rows' => '0',
    'skipped_blank_amount_rows' => '0',
    'error_summary' => 'Validated 110 row(s), but 10 row(s) failed validation.',
    'rolled_back_at' => null,
    'rolled_back_rows' => '0',
    'rollback_linked_rows' => '108',
    'rollback_active_rows' => '108',
]);
assertSame('import_123', $importRunItem['id'], 'data runs import item keeps stable prefixed id');
assertSame('import', $importRunItem['type'], 'data runs import item keeps type');
assertSame('partial', $importRunItem['status'], 'data runs import item keeps normalized partial status');
assertSame(120, $importRunItem['total_rows'], 'data runs import item casts total rows');
assertSame(108, $importRunItem['imported_rows'], 'data runs import item casts imported rows');
assertSame(null, $importRunItem['date_from'], 'data runs import item has no date range');
assertSame(true, $importRunItem['rollback_available'], 'data runs import item exposes rollback for linked active rows');
assertSame(null, $importRunItem['rolled_back_at'], 'data runs import item keeps null rollback timestamp');
assertSame(0, $importRunItem['rolled_back_rows'], 'data runs import item casts rollback row count');
assertSame(null, $importRunItem['rollback_unavailable_reason'], 'data runs import item has no unavailable reason when rollback is available');
$oldImportRunItem = $dataRunItem->invoke($dataRunRepository, [
    'id' => 'import_124',
    'type' => 'import',
    'status' => 'completed',
    'created_at' => '2026-05-29 12:02:00',
    'source_filename' => 'old.csv',
    'date_from' => null,
    'date_to' => null,
    'total_rows' => '12',
    'valid_rows' => '12',
    'imported_rows' => '12',
    'duplicate_rows' => '0',
    'invalid_rows' => '0',
    'skipped_rows' => '0',
    'skipped_blank_amount_rows' => '0',
    'error_summary' => null,
    'rolled_back_at' => null,
    'rolled_back_rows' => '0',
    'rollback_linked_rows' => '0',
    'rollback_active_rows' => '0',
]);
assertSame(false, $oldImportRunItem['rollback_available'], 'old import run without linked rows cannot roll back');
assertSame('pre_rollback_feature', $oldImportRunItem['rollback_unavailable_reason'], 'old import run reports rollback unavailable reason');
$rolledBackImportRunItem = $dataRunItem->invoke($dataRunRepository, [
    'id' => 'import_125',
    'type' => 'import',
    'status' => 'completed',
    'created_at' => '2026-05-29 12:03:00',
    'source_filename' => 'rolled.csv',
    'date_from' => null,
    'date_to' => null,
    'total_rows' => '5',
    'valid_rows' => '5',
    'imported_rows' => '5',
    'duplicate_rows' => '0',
    'invalid_rows' => '0',
    'skipped_rows' => '0',
    'skipped_blank_amount_rows' => '0',
    'error_summary' => null,
    'rolled_back_at' => '2026-05-29 12:04:00',
    'rolled_back_rows' => '5',
    'rollback_linked_rows' => '5',
    'rollback_active_rows' => '0',
]);
assertSame(false, $rolledBackImportRunItem['rollback_available'], 'rolled back import cannot roll back again');
assertSame('2026-05-29 12:04:00', $rolledBackImportRunItem['rolled_back_at'], 'rolled back import keeps timestamp');
assertSame(5, $rolledBackImportRunItem['rolled_back_rows'], 'rolled back import casts rolled back count');
$exportRunItem = $dataRunItem->invoke($dataRunRepository, [
    'id' => 'export_45',
    'type' => 'export',
    'status' => 'completed',
    'created_at' => '2026-05-29 12:05:00',
    'source_filename' => null,
    'date_from' => '2026-01-01',
    'date_to' => '2026-03-31',
    'total_rows' => '240',
    'valid_rows' => null,
    'imported_rows' => null,
    'duplicate_rows' => null,
    'invalid_rows' => null,
    'skipped_rows' => null,
    'skipped_blank_amount_rows' => null,
    'error_summary' => null,
]);
assertSame('export_45', $exportRunItem['id'], 'data runs export item keeps stable prefixed id');
assertSame('export', $exportRunItem['type'], 'data runs export item keeps type');
assertSame('2026-01-01', $exportRunItem['date_from'], 'data runs export item keeps date_from');
assertSame(null, $exportRunItem['valid_rows'], 'data runs export item has null import counters');
assertSame(240, $exportRunItem['total_rows'], 'data runs export item casts total rows');
$mapperReflection = new ReflectionClass(CsvImportMapper::class);
$suggestImportMapping = $mapperReflection->getMethod('suggestImportMapping');
$suggestedMapping = $suggestImportMapping->invoke($csvImportMapper, ['Posted Date', 'Description', 'Amount', 'Budget Category', 'Tag', 'Account']);
assertSame('Posted Date', $suggestedMapping['date'] ?? null, 'csv import preview suggests date mapping from alias');
assertSame('Description', $suggestedMapping['expense'] ?? null, 'csv import preview suggests expense mapping from alias');
assertSame('Account', $suggestedMapping['card'] ?? null, 'csv import preview suggests card mapping from alias');
$bankSuggestedMapping = $suggestImportMapping->invoke($csvImportMapper, ['Transaction Date', 'Vendor / Payee', 'Money Out', 'Money In', 'Bank Category Guess', 'Payment Source']);
assertSame('Vendor / Payee', $bankSuggestedMapping['expense'] ?? null, 'csv import preview suggests bank vendor/payee mapping');
assertSame('Money Out', $bankSuggestedMapping['amount'] ?? null, 'csv import preview suggests bank money out mapping');
assertSame('Bank Category Guess', $bankSuggestedMapping['tag'] ?? null, 'csv import preview suggests bank category guess tag mapping');
assertSame('Payment Source', $bankSuggestedMapping['card'] ?? null, 'csv import preview suggests bank payment source card mapping');
$validatedImportMapping = $mapperReflection->getMethod('validatedImportMapping');
$mapping = $validatedImportMapping->invoke($csvImportMapper, json_encode([
    'date' => 'Posted Date',
    'expense' => 'Description',
    'amount' => 'Amount',
    'category' => 'Budget Category',
    'tag' => 'Tag',
    'card' => 'Account',
    'notes' => 'Memo',
], JSON_THROW_ON_ERROR), ['Posted Date', 'Description', 'Amount', 'Budget Category', 'Tag', 'Account', 'Memo']);
assertSame(0, $mapping['date'], 'csv import mapping resolves date index');
assertSame(5, $mapping['card'], 'csv import mapping resolves optional card index');
assertSame(6, $mapping['notes'], 'csv import mapping resolves optional notes index alongside other optional fields');
$notesSuggestedMapping = $suggestImportMapping->invoke($csvImportMapper, ['Posted Date', 'Description', 'Amount', 'Tag', 'Memo']);
assertSame('Memo', $notesSuggestedMapping['notes'] ?? null, 'csv import preview suggests memo mapping for notes');
$notesMapping = $validatedImportMapping->invoke($csvImportMapper, json_encode([
    'date' => 'Posted Date',
    'expense' => 'Description',
    'amount' => 'Amount',
    'category' => 'Budget Category',
    'tag' => 'Tag',
    'notes' => 'Memo',
], JSON_THROW_ON_ERROR), ['Posted Date', 'Description', 'Amount', 'Budget Category', 'Tag', 'Memo']);
assertSame(5, $notesMapping['notes'], 'csv import mapping resolves optional notes index');
$valueMapMapping = $validatedImportMapping->invoke($csvImportMapper, json_encode([
    'date' => 'Posted Date',
    'expense' => 'Description',
    'amount' => 'Amount',
    'tag' => 'Bank Category Guess',
], JSON_THROW_ON_ERROR), ['Posted Date', 'Description', 'Amount', 'Bank Category Guess'], ['mode' => 'value_map']);
assertSame(3, $valueMapMapping['tag'], 'csv import mapping allows category to be omitted for value map strategy');
expectHttpException(
    fn() => $validatedImportMapping->invoke($csvImportMapper, '{"date":"Posted Date","expense":"Posted Date","amount":"Amount","category":"Budget Category","tag":"Tag"}', ['Posted Date', 'Amount', 'Budget Category', 'Tag']),
    422,
    'VALIDATION_ERROR',
    'csv import mapping rejects reused headers'
);
expectHttpException(
    fn() => $validatedImportMapping->invoke($csvImportMapper, '{"date":"Missing","expense":"Description","amount":"Amount","category":"Budget Category","tag":"Tag"}', ['Description', 'Amount', 'Budget Category', 'Tag']),
    422,
    'VALIDATION_ERROR',
    'csv import mapping rejects missing source headers'
);
$parseImportRow = $mapperReflection->getMethod('parseImportRow');
$dateStrategyReject = ['missing_year' => 'reject'];
$dateStrategyApplyYear = ['missing_year' => 'apply_year', 'year' => 2026];
$tagStrategy = ['mode' => 'value_map', 'value_map' => [
    'Coffee' => ['mode' => 'new', 'tag_id' => null, 'tag_name' => 'Coffee'],
    'Utilities' => ['mode' => 'new', 'tag_id' => null, 'tag_name' => 'Utilities'],
    'Refund' => ['mode' => 'new', 'tag_id' => null, 'tag_name' => 'Refund'],
    'Debt' => ['mode' => 'new', 'tag_id' => null, 'tag_name' => 'Debt'],
]];
$parsedImportRow = $parseImportRow->invoke($csvImportMapper, ['6/1/2026', 'Coffee Shop', '$6.25', 'Wants', 'Coffee', 'Amex Gold', 'yes', '  Bought snacks  '], [
    'date' => 0,
    'expense' => 1,
    'amount' => 2,
    'category' => 3,
    'tag' => 4,
    'card' => 5,
    'is_split' => 6,
    'notes' => 7,
], ['mode' => 'exact_column'], ['blank_mapped_amount' => 'error'], $dateStrategyReject, $tagStrategy, 2);
assertSame('2026-06-01', $parsedImportRow['date'], 'csv import row normalizes mapped date');
assertSame('6.25', $parsedImportRow['amount'], 'csv import row normalizes mapped amount');
assertSame('wants', $parsedImportRow['category'], 'csv import row normalizes mapped category');
assertSame(true, $parsedImportRow['is_split'], 'csv import row normalizes mapped split flag');
assertSame('Bought snacks', $parsedImportRow['notes'], 'csv import row trims mapped notes');
$savingsImportRow = $parseImportRow->invoke($csvImportMapper, ['6/1/2026', 'Brokerage Transfer', '$100.00', 'Savings', 'Utilities'], [
    'date' => 0,
    'expense' => 1,
    'amount' => 2,
    'category' => 3,
    'tag' => 4,
], ['mode' => 'exact_column'], ['blank_mapped_amount' => 'error'], $dateStrategyReject, $tagStrategy, 2);
assertSame('savings', $savingsImportRow['category'], 'csv import row accepts savings category');
$debtImportRow = $parseImportRow->invoke($csvImportMapper, ['6/1/2026', 'Loan Payment', '$100.00', 'Credit Card Payment', 'Debt'], [
    'date' => 0,
    'expense' => 1,
    'amount' => 2,
    'category' => 3,
    'tag' => 4,
], ['mode' => 'exact_column'], ['blank_mapped_amount' => 'error'], $dateStrategyReject, $tagStrategy, 2);
assertSame('needs', $debtImportRow['category'], 'csv import row maps debt-like categories to needs');
$yearlessImportRow = $parseImportRow->invoke($csvImportMapper, ['3/12', 'Coffee Shop', '$6.25', 'Wants', 'Coffee'], [
    'date' => 0,
    'expense' => 1,
    'amount' => 2,
    'category' => 3,
    'tag' => 4,
], ['mode' => 'exact_column'], ['blank_mapped_amount' => 'error'], $dateStrategyApplyYear, $tagStrategy, 2);
assertSame('2026-03-12', $yearlessImportRow['date'], 'csv import row applies selected year to yearless dates');
expectHttpException(
    fn() => $parseImportRow->invoke($csvImportMapper, ['3/12', 'Coffee Shop', '$6.25', 'Wants', 'Coffee'], [
        'date' => 0,
        'expense' => 1,
        'amount' => 2,
        'category' => 3,
        'tag' => 4,
    ], ['mode' => 'exact_column'], ['blank_mapped_amount' => 'error'], $dateStrategyReject, $tagStrategy, 2),
    422,
    'VALIDATION_ERROR',
    'csv import row rejects yearless dates without date strategy'
);
$valueMapImportRow = $parseImportRow->invoke($csvImportMapper, ['6/1/2026', 'LADWP', '49.61', 'Utilities'], [
    'date' => 0,
    'expense' => 1,
    'amount' => 2,
    'tag' => 3,
], ['mode' => 'value_map', 'source_index' => 3, 'value_map' => ['Utilities' => 'needs']], ['blank_mapped_amount' => 'error'], $dateStrategyReject, $tagStrategy, 2);
assertSame('needs', $valueMapImportRow['category'], 'csv import row resolves category from value map strategy');
assertSame('Utilities', $valueMapImportRow['tag_name'], 'csv import row allows the category source to also feed tag');
$blankAmountImportRow = $parseImportRow->invoke($csvImportMapper, ['6/1/2026', 'Refund', '', 'Refund'], [
    'date' => 0,
    'expense' => 1,
    'amount' => 2,
    'tag' => 3,
], ['mode' => 'default', 'default_category' => 'needs'], ['blank_mapped_amount' => 'skip'], $dateStrategyReject, $tagStrategy, 2);
assertSame(null, $blankAmountImportRow, 'csv import row skips blank mapped amount rows when configured');
$blankNotesImportRow = $parseImportRow->invoke($csvImportMapper, ['6/1/2026', 'Coffee Shop', '$6.25', 'Wants', 'Coffee', '   '], [
    'date' => 0,
    'expense' => 1,
    'amount' => 2,
    'category' => 3,
    'tag' => 4,
    'notes' => 5,
], ['mode' => 'exact_column'], ['blank_mapped_amount' => 'error'], $dateStrategyReject, $tagStrategy, 2);
assertSame(null, $blankNotesImportRow['notes'], 'csv import row stores blank mapped notes as null');
expectHttpException(
    fn() => $parseImportRow->invoke($csvImportMapper, ['6/1/2026', 'Coffee Shop', '$6.25', 'Wants', 'Coffee', str_repeat('é', 256)], [
        'date' => 0,
        'expense' => 1,
        'amount' => 2,
        'category' => 3,
        'tag' => 4,
        'notes' => 5,
    ], ['mode' => 'exact_column'], ['blank_mapped_amount' => 'error'], $dateStrategyReject, $tagStrategy, 2),
    422,
    'VALIDATION_ERROR',
    'csv import row rejects overlong utf8 notes'
);
$inferredTagIconKey = $mapperReflection->getMethod('inferredTagIconKey');
assertSame('coffee', $inferredTagIconKey->invoke($csvImportMapper, 'Coffee Shops'), 'csv import infers coffee icon');
assertSame('tag', $inferredTagIconKey->invoke($csvImportMapper, 'Miscellaneous'), 'csv import falls back to tag icon');
$csvImportReader = new CsvImportReader(Config::load(dirname(__DIR__)), $csvImportMapper);
$readerReflection = new ReflectionClass(CsvImportReader::class);
$readImportCsv = $readerReflection->getMethod('readImportCsv');
$csvHandle = fopen('php://temp', 'r+');
assert($csvHandle !== false);
fwrite($csvHandle, "Posted Date,Description,Amount,Bank Category Guess\n2026-06-01,Coffee,6.25,Coffee\n2026-06-02,Refund,,Refund\n");
rewind($csvHandle);
$csvPreview = $readImportCsv->invoke($csvImportReader, $csvHandle, 10, true);
fclose($csvHandle);
assertSame(['Posted Date', 'Description', 'Amount', 'Bank Category Guess'], $csvPreview['header'], 'csv import preview reads headers');
assertSame(2, $csvPreview['total_rows'], 'csv import preview counts data rows');
assertSame('Coffee', $csvPreview['sample_rows'][0]['Description'] ?? null, 'csv import preview returns sample rows by header');
assertSame(1, $csvPreview['column_profiles'][2]['blank_count'], 'csv import preview profiles blank counts');
assertSame('Coffee', $csvPreview['column_profiles'][3]['unique_values'][0]['value'] ?? null, 'csv import preview profiles unique source values');

$importPdo = new PDO('sqlite::memory:');
$importPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$importPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$importPdo->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, name TEXT NOT NULL, icon_key TEXT NULL, is_active INTEGER NOT NULL DEFAULT 1, deleted_at TEXT NULL, updated_at TEXT NULL)');
$importPdo->exec('CREATE TABLE cards (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, name TEXT NOT NULL, is_favorite INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1, deleted_at TEXT NULL, updated_at TEXT NULL)');
$importPdo->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, transaction_date TEXT NOT NULL, expense TEXT NOT NULL, amount TEXT NOT NULL, category TEXT NOT NULL, is_split INTEGER NOT NULL DEFAULT 0, notes TEXT NULL, tag_id INTEGER NOT NULL, card_id INTEGER NULL, source TEXT NOT NULL DEFAULT "manual", import_fingerprint TEXT NULL, csv_import_run_id INTEGER NULL, deleted_at TEXT NULL, updated_at TEXT NULL)');
$importPdo->exec('CREATE UNIQUE INDEX uq_transactions_import_dedupe ON transactions (user_id, import_fingerprint)');
$importPdo->exec("INSERT INTO tags (user_id, name, icon_key, is_active) VALUES (1, 'Coffee', 'coffee', 1)");
$importPdo->exec("INSERT INTO cards (user_id, name, is_active) VALUES (1, 'Amex Gold', 1)");
$importPdo->exec("INSERT INTO transactions (user_id, transaction_date, expense, amount, category, is_split, notes, tag_id, card_id, source) VALUES (1, '2026-06-01', 'Coffee Shop', '6.25', 'wants', 1, 'Existing note', 1, 1, 'manual')");
$taxonomyImportRepository = new TaxonomyImportRepository($importPdo, $csvImportMapper);
$csvImportCommitter = new CsvImportCommitter($importPdo, $taxonomyImportRepository, $csvImportMapper);
$parsedRowsForBatch = [
    [
        'date' => '2026-06-01',
        'expense' => 'Coffee Shop',
        'amount' => '6.25',
        'category' => 'wants',
        'is_split' => true,
        'notes' => 'Morning coffee',
        'tag_name' => 'Coffee',
        'tag_id' => null,
        'card_name' => 'Amex Gold',
        'row' => 2,
    ],
    [
        'date' => '2026-06-02',
        'expense' => 'LADWP',
        'amount' => '49.61',
        'category' => 'needs',
        'is_split' => false,
        'notes' => 'First import note',
        'tag_name' => 'Utilities',
        'tag_id' => null,
        'card_name' => 'Visa',
        'row' => 3,
    ],
];
assertSame(1, $csvImportCommitter->estimateDryRunDuplicates(1, $parsedRowsForBatch), 'csv import committer batches duplicate estimation with existing tag/card names');
assertSame([['name' => 'Utilities', 'icon_key' => 'home']], $csvImportCommitter->plannedNewTags(1, $parsedRowsForBatch), 'csv import committer plans only missing new tags');
assertSame([['name' => 'Visa']], $csvImportCommitter->plannedNewCards(1, $parsedRowsForBatch), 'csv import committer plans only missing new cards');
$commitRowsForBatch = [
    $parsedRowsForBatch[0],
    $parsedRowsForBatch[1],
    $parsedRowsForBatch[1],
];
$commitResult = $csvImportCommitter->commitRows(1, $commitRowsForBatch, 99);
assertSame(1, $commitResult['imported_rows'], 'csv import committer imports one new row after batch duplicate checks');
assertSame(2, $commitResult['duplicate_rows'], 'csv import committer skips existing DB duplicates and in-file duplicates');
assertSame(1, (int) $importPdo->query("SELECT COUNT(*) AS total FROM tags WHERE user_id = 1 AND LOWER(name) = 'utilities'")->fetch()['total'], 'csv import committer creates a missing tag once per import');
assertSame(1, (int) $importPdo->query("SELECT COUNT(*) AS total FROM cards WHERE user_id = 1 AND LOWER(name) = 'visa'")->fetch()['total'], 'csv import committer creates a missing card once per import');
assertSame(1, (int) $importPdo->query("SELECT COUNT(*) AS total FROM transactions WHERE user_id = 1 AND expense = 'LADWP' AND source = 'import'")->fetch()['total'], 'csv import committer reuses created taxonomy for duplicate in-file rows');
assertSame('First import note', $importPdo->query("SELECT notes FROM transactions WHERE user_id = 1 AND expense = 'LADWP' AND source = 'import'")->fetch()['notes'] ?? null, 'csv import committer persists imported notes');
$importPdo->exec("UPDATE transactions SET deleted_at = '2026-06-03' WHERE user_id = 1 AND expense = 'LADWP' AND source = 'import'");
$uniqueConflictResult = $csvImportCommitter->commitRows(1, [$parsedRowsForBatch[1]], 100);
assertSame(0, $uniqueConflictResult['imported_rows'], 'csv import committer treats unique fingerprint conflicts as duplicates');
assertSame(1, $uniqueConflictResult['duplicate_rows'], 'csv import committer keeps unique constraint as the final duplicate guard');
$notesChangedDuplicateRow = $parsedRowsForBatch[0];
$notesChangedDuplicateRow['notes'] = 'Changed duplicate note';
assertSame(1, $csvImportCommitter->estimateDryRunDuplicates(1, [$notesChangedDuplicateRow]), 'csv import duplicate detection ignores notes changes');

$exportPdo = new PDO('sqlite::memory:');
$exportPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$exportPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$exportPdo->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, name TEXT NOT NULL, icon_key TEXT NULL)');
$exportPdo->exec('CREATE TABLE cards (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, name TEXT NOT NULL, is_favorite INTEGER NOT NULL DEFAULT 0)');
$exportPdo->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, transaction_date TEXT NOT NULL, expense TEXT NOT NULL, amount TEXT NOT NULL, category TEXT NOT NULL, is_split INTEGER NOT NULL DEFAULT 0, notes TEXT NULL, tag_id INTEGER NOT NULL, card_id INTEGER NULL, source TEXT NOT NULL DEFAULT "manual", deleted_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$exportPdo->exec('CREATE TABLE csv_export_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, status TEXT NOT NULL, date_from TEXT NULL, date_to TEXT NULL, total_rows INTEGER NOT NULL DEFAULT 0, error_summary TEXT NULL, completed_at TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$exportPdo->exec("INSERT INTO tags (id, user_id, name, icon_key) VALUES (1, 7, 'Groceries', 'shopping_cart')");
$exportPdo->exec("INSERT INTO cards (id, user_id, name, is_favorite) VALUES (1, 7, 'Chase Sapphire', 1)");
$exportPdo->exec("INSERT INTO transactions (user_id, transaction_date, expense, amount, category, is_split, notes, tag_id, card_id, source, deleted_at, created_at, updated_at) VALUES
    (7, '2026-06-01', 'Trader Joe''s', '72.43', 'needs', 0, NULL, 1, 1, 'manual', NULL, '2026-06-01 12:00:00', '2026-06-01 12:00:00'),
    (7, '2026-06-02', 'Payroll', '1.00', 'needs', 0, '=SUM(A1:A2)', 1, NULL, 'manual', NULL, '2026-06-02 12:00:00', '2026-06-02 12:00:00')");
$csvExportService = new CsvExportService($exportPdo, $csvImportMapper, new DataRunRepository($exportPdo));
$exportResponse = $csvExportService->exportCsv(7, []);
ob_start();
$exportResponse->send();
$exportCsv = ob_get_clean();
assertTrue(is_string($exportCsv), 'csv export response streams csv');
$exportLines = preg_split("/\r\n|\n|\r/", trim((string) $exportCsv)) ?: [];
assertSame('date,expense,amount,category,is_split,tag,card,created_at,updated_at,notes', $exportLines[0] ?? null, 'csv export appends notes column at the end');
assertTrue(str_contains($exportLines[1] ?? '', "'=SUM(A1:A2)"), 'csv export formula-escapes notes cells');
assertTrue(str_ends_with($exportLines[2] ?? '', ','), 'csv export writes blank notes cells for null notes');

$taxonomyReflection = new ReflectionClass(TaxonomyController::class);
$taxonomyController = $taxonomyReflection->newInstanceWithoutConstructor();
$quickPickLimit = $taxonomyReflection->getMethod('clampedQuickPickLimit');
assertSame(5, $quickPickLimit->invoke($taxonomyController, null), 'tag quick picks default to five');
assertSame(1, $quickPickLimit->invoke($taxonomyController, '0'), 'tag quick pick limit clamps minimum');
assertSame(10, $quickPickLimit->invoke($taxonomyController, '25'), 'tag quick pick limit clamps maximum');
expectHttpException(
    fn() => $quickPickLimit->invoke($taxonomyController, 'many'),
    422,
    'VALIDATION_ERROR',
    'tag quick picks reject nonnumeric limit'
);
$buildTagQuickPicks = $taxonomyReflection->getMethod('buildTagQuickPicks');
$quickPicks = $buildTagQuickPicks->invoke($taxonomyController, [
    [
        'id' => '3',
        'name' => 'Coffee',
        'icon_key' => 'coffee',
        'usage_count' => '10',
        'last_used_at' => '2026-05-10',
    ],
    [
        'id' => '2',
        'name' => 'Dining',
        'icon_key' => null,
        'usage_count' => '2',
        'last_used_at' => '2026-05-29',
    ],
    [
        'id' => '1',
        'name' => 'Groceries',
        'icon_key' => 'shopping_cart',
        'usage_count' => '2',
        'last_used_at' => '2026-05-29',
    ],
], [
    ['id' => '3', 'name' => 'Coffee', 'icon_key' => 'coffee'],
    ['id' => '4', 'name' => 'Gas', 'icon_key' => 'car'],
    ['id' => '5', 'name' => 'Rent', 'icon_key' => 'home'],
], 5);
assertSame('3', $quickPicks[0]['id'], 'tag quick picks rank highest transaction count first');
assertSame('2', $quickPicks[1]['id'], 'tag quick picks break equal-count recency ties by tag name');
assertSame('1', $quickPicks[2]['id'], 'tag quick picks include same-recency history before fallback tags');
assertSame('4', $quickPicks[3]['id'], 'tag quick picks fill remaining slots from active tags');
assertSame('5', $quickPicks[4]['id'], 'tag quick picks keep fallback alphabetical order');

$transactionReflection = new ReflectionClass(TransactionController::class);
$transactionController = $transactionReflection->newInstanceWithoutConstructor();
$validatedSuggestionQuery = $transactionReflection->getMethod('validatedSuggestionQuery');
assertSame('Trader Joe', $validatedSuggestionQuery->invoke($transactionController, ' Trader   Joe '), 'suggestion query trims and compacts whitespace');
expectHttpException(
    fn() => $validatedSuggestionQuery->invoke($transactionController, 'a'),
    422,
    'VALIDATION_ERROR',
    'suggestions reject short query'
);
$validatedTransactionCategory = $transactionReflection->getMethod('validatedCategory');
assertSame('savings', $validatedTransactionCategory->invoke($transactionController, 'savings'), 'transaction validation accepts savings category');
expectHttpException(
    fn() => $validatedTransactionCategory->invoke($transactionController, 'savings_debts'),
    422,
    'VALIDATION_ERROR',
    'transaction validation rejects legacy savings_debts category'
);
$buildTransactionSuggestions = $transactionReflection->getMethod('buildTransactionSuggestions');
$suggestions = $buildTransactionSuggestions->invoke($transactionController, [
    [
        'id' => '1',
        'expense' => 'Trader Joe\'s',
        'category' => 'needs',
        'is_split' => '0',
        'transaction_date' => '2026-05-20',
        'tag_id' => '12',
        'tag_name' => 'Groceries',
        'tag_icon_key' => 'shopping_cart',
        'card_id' => '4',
        'card_name' => 'Chase Sapphire',
    ],
    [
        'id' => '2',
        'expense' => 'Trader Joe\'s',
        'category' => 'needs',
        'is_split' => '0',
        'transaction_date' => '2026-05-28',
        'tag_id' => '12',
        'tag_name' => 'Groceries',
        'tag_icon_key' => 'shopping_cart',
        'card_id' => '4',
        'card_name' => 'Chase Sapphire',
    ],
    [
        'id' => '3',
        'expense' => 'Trader Joe\'s Express',
        'category' => 'wants',
        'is_split' => '1',
        'transaction_date' => '2026-05-29',
        'tag_id' => '8',
        'tag_name' => 'Dining',
        'tag_icon_key' => null,
        'card_id' => null,
        'card_name' => null,
    ],
    [
        'id' => '4',
        'expense' => 'Downtown Trader Joe Market',
        'category' => 'needs',
        'is_split' => '0',
        'transaction_date' => '2026-05-30',
        'tag_id' => '12',
        'tag_name' => 'Groceries',
        'tag_icon_key' => 'shopping_cart',
        'card_id' => '5',
        'card_name' => 'Debit',
    ],
], 'Trader Joe\'s');
assertSame('Trader Joe\'s', $suggestions[0]['expense'], 'suggestions rank exact match first');
assertSame('Groceries', $suggestions[0]['tag']['name'], 'suggestions choose most common tag');
assertSame('needs', $suggestions[0]['category'], 'suggestions choose most common category');
assertSame('Chase Sapphire', $suggestions[0]['card']['name'], 'suggestions choose most common card');
assertSame(false, $suggestions[0]['is_split'], 'suggestions choose most common split state');
assertSame('high', $suggestions[0]['confidence'], 'repeated exact suggestions are high confidence');
$tieSuggestions = $buildTransactionSuggestions->invoke($transactionController, [
    [
        'id' => '10',
        'expense' => 'Target',
        'category' => 'needs',
        'is_split' => '0',
        'transaction_date' => '2026-05-01',
        'tag_id' => '12',
        'tag_name' => 'Groceries',
        'tag_icon_key' => null,
        'card_id' => null,
        'card_name' => null,
    ],
    [
        'id' => '11',
        'expense' => 'Target',
        'category' => 'wants',
        'is_split' => '0',
        'transaction_date' => '2026-05-30',
        'tag_id' => '8',
        'tag_name' => 'Shopping',
        'tag_icon_key' => null,
        'card_id' => null,
        'card_name' => null,
    ],
], 'target');
assertSame('wants', $tieSuggestions[0]['category'], 'suggestions break equal-count category ties by recency');
assertSame('Shopping', $tieSuggestions[0]['tag']['name'], 'suggestions break equal-count tag ties by recency');

$suggestionRow = static function (int $id, string $date, string $cardId, string $cardName, string $category = 'needs', string $tagId = '12', string $tagName = 'Groceries', string $expense = 'Target'): array {
    return [
        'id' => (string) $id,
        'expense' => $expense,
        'category' => $category,
        'is_split' => '0',
        'transaction_date' => $date,
        'tag_id' => $tagId,
        'tag_name' => $tagName,
        'tag_icon_key' => null,
        'card_id' => $cardId,
        'card_name' => $cardName,
    ];
};
$frequencySuggestions = $buildTransactionSuggestions->invoke($transactionController, [
    $suggestionRow(20, '2026-05-01', '1', 'Apple Card'),
    $suggestionRow(21, '2026-05-02', '2', 'Chase'),
    $suggestionRow(22, '2026-05-03', '2', 'Chase'),
    $suggestionRow(23, '2026-05-04', '2', 'Chase'),
], 'target');
assertSame('Chase', $frequencySuggestions[0]['card']['name'], 'suggestions preserve most-common setup behavior');
assertSame(3, $frequencySuggestions[0]['usage_count'], 'suggestion usage count is for the winning setup');

$oneOffSuggestions = $buildTransactionSuggestions->invoke($transactionController, [
    $suggestionRow(30, '2026-05-01', '1', 'Apple Card'),
    $suggestionRow(31, '2026-05-02', '1', 'Apple Card'),
    $suggestionRow(32, '2026-05-03', '1', 'Apple Card'),
    $suggestionRow(33, '2026-05-04', '1', 'Apple Card'),
    $suggestionRow(34, '2026-05-05', '2', 'Chase'),
], 'target');
assertSame('Apple Card', $oneOffSuggestions[0]['card']['name'], 'one recent setup deviation does not retrain suggestions');
assertSame(4, $oneOffSuggestions[0]['usage_count'], 'one-off suggestion uses recent-window setup count');

$switchedSuggestions = $buildTransactionSuggestions->invoke($transactionController, array_merge(
    array_map(fn(int $id): array => $suggestionRow($id, '2026-01-' . str_pad((string) ($id - 99), 2, '0', STR_PAD_LEFT), '1', 'Apple Card'), range(100, 119)),
    [
        $suggestionRow(120, '2026-06-01', '1', 'Apple Card'),
        $suggestionRow(121, '2026-06-02', '1', 'Apple Card'),
        $suggestionRow(122, '2026-06-03', '2', 'Chase'),
        $suggestionRow(123, '2026-06-04', '2', 'Chase'),
        $suggestionRow(124, '2026-06-05', '2', 'Chase'),
    ]
), 'target');
assertSame('Chase', $switchedSuggestions[0]['card']['name'], 'recent consistent setup changes over stale lifetime frequency');
assertSame(3, $switchedSuggestions[0]['usage_count'], 'switched suggestion counts only the recent history window');

$auditReflection = new ReflectionClass(AuditLogger::class);
$auditLogger = $auditReflection->newInstanceWithoutConstructor();
$redactMetadata = $auditReflection->getMethod('redactMetadata');
$redactedMetadata = $redactMetadata->invoke($auditLogger, [
    'invite_token' => 'secret-token',
    'password' => 'secret-password',
    'profile' => [
        'verification_code' => '123456',
        'email' => 'owner@example.com',
    ],
]);
assertSame('[redacted]', $redactedMetadata['invite_token'], 'audit logger redacts token metadata');
assertSame('[redacted]', $redactedMetadata['password'], 'audit logger redacts password metadata');
assertSame('[redacted]', $redactedMetadata['profile']['verification_code'], 'audit logger redacts nested code metadata');
assertSame('owner@example.com', $redactedMetadata['profile']['email'], 'audit logger keeps safe nested metadata');

$appReflection = new ReflectionClass(App::class);
$app = $appReflection->newInstanceWithoutConstructor();
$normalizePath = $appReflection->getMethod('normalizePath');
$sessionIdFromToken = $appReflection->getMethod('sessionIdFromToken');
$requestId = $appReflection->getMethod('requestId');
assertSame('/me/dashboard', $normalizePath->invoke($app, '/api/v1/me/dashboard'), 'rate limiter normalizes api v1 paths');
assertSame('/me/dashboard', $normalizePath->invoke($app, '/me/dashboard'), 'rate limiter keeps direct paths');
assertSame('ses_abc', $sessionIdFromToken->invoke($app, 'ses_abc.secret'), 'rate limiter extracts session id from token');
assertSame(null, $sessionIdFromToken->invoke($app, 'broken-token'), 'rate limiter rejects malformed session token');
assertSame('req_client_123', $requestId->invoke($app, new Request('GET', '/health', '', [], [], [], [], ['X-Request-ID' => 'req_client_123'])), 'request id accepts safe client header');
assertMatches('/^req_[a-f0-9]{24}$/', $requestId->invoke($app, new Request('GET', '/health', '', [], [], [], [], ['X-Request-ID' => 'bad value'])), 'request id rejects unsafe client header');

$configReflection = new ReflectionClass(Config::class);
$config = $configReflection->newInstanceWithoutConstructor();
$valuesProperty = $configReflection->getProperty('values');
$valuesProperty->setValue($config, ['APP_ENV' => 'test']);
$structuredLogger = new StructuredLogger($config);
$structuredLog = json_decode($structuredLogger->format('error', 'server_error', 'Failed with token=abc123', [
    'password' => 'secret-password',
    'nested' => [
        'api_key' => 'secret-key',
        'safe' => 'visible',
    ],
]), true);
assertSame('budget-api', $structuredLog['service'] ?? null, 'structured logger includes service');
assertSame('test', $structuredLog['environment'] ?? null, 'structured logger includes environment');
assertSame('Failed with token=[redacted]', $structuredLog['message'] ?? null, 'structured logger redacts sensitive message fragments');
assertSame('[redacted]', $structuredLog['context']['password'] ?? null, 'structured logger redacts sensitive top-level context');
assertSame('[redacted]', $structuredLog['context']['nested']['api_key'] ?? null, 'structured logger redacts sensitive nested context');
assertSame('visible', $structuredLog['context']['nested']['safe'] ?? null, 'structured logger keeps non-sensitive context');

$apiKeyReflection = new ReflectionClass(MasterApiKeyController::class);
$apiKeyController = $apiKeyReflection->newInstanceWithoutConstructor();
$apiKeyStatus = $apiKeyReflection->getMethod('apiKeyStatus');
assertSame('active', $apiKeyStatus->invoke($apiKeyController, [
    'is_active' => 1,
    'revoked_at' => null,
    'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
]), 'api key status reports active unexpired key');
assertSame('expired', $apiKeyStatus->invoke($apiKeyController, [
    'is_active' => 1,
    'revoked_at' => null,
    'expires_at' => gmdate('Y-m-d H:i:s', time() - 3600),
]), 'api key status reports expired key');
assertSame('revoked', $apiKeyStatus->invoke($apiKeyController, [
    'is_active' => 0,
    'revoked_at' => gmdate('Y-m-d H:i:s'),
    'expires_at' => null,
]), 'api key status reports revoked key');

$authReflection = new ReflectionClass(AuthController::class);
$authController = $authReflection->newInstanceWithoutConstructor();
$validatePassword = $authReflection->getMethod('validatePassword');
$validatePassword->invoke($authController, 'Strong123');
expectHttpException(
    fn() => $validatePassword->invoke($authController, 'short'),
    422,
    'VALIDATION_ERROR',
    'password reset rejects short passwords'
);

$closeoutPdo = new PDO('sqlite::memory:');
$closeoutPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$closeoutPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$closeoutPdo->exec('CREATE TABLE budget_settings_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    effective_month TEXT NOT NULL,
    monthly_income TEXT NOT NULL,
    income_source_type TEXT NOT NULL,
    primary_monthly_income TEXT NULL,
    primary_hourly_rate TEXT NULL,
    primary_weekly_hours TEXT NULL,
    side_income_type TEXT NOT NULL,
    side_income_label TEXT NULL,
    side_monthly_income TEXT NULL,
    side_hourly_rate TEXT NULL,
    side_weekly_hours TEXT NULL,
    allocation_mode TEXT NOT NULL,
    needs_percent TEXT NULL,
    wants_percent TEXT NULL,
    savings_percent TEXT NULL,
    needs_amount TEXT NULL,
    wants_amount TEXT NULL,
    savings_amount TEXT NULL,
    created_at TEXT NULL,
    updated_at TEXT NULL
)');
$closeoutPdo->exec('CREATE TABLE tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    icon_key TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    deleted_at TEXT NULL,
    created_at TEXT NULL,
    updated_at TEXT NULL
)');
$closeoutPdo->exec('CREATE TABLE cards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    is_favorite INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    deleted_at TEXT NULL,
    created_at TEXT NULL,
    updated_at TEXT NULL
)');
$closeoutPdo->exec('CREATE TABLE funds (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fund_id TEXT NOT NULL,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    fund_type TEXT NOT NULL,
    goal_amount TEXT NULL,
    target_month TEXT NULL,
    notes TEXT NULL,
    status TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    archived_at TEXT NULL,
    created_at TEXT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NULL DEFAULT CURRENT_TIMESTAMP
)');
$closeoutPdo->exec('CREATE TABLE transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    transaction_date TEXT NOT NULL,
    expense TEXT NOT NULL,
    amount TEXT NOT NULL,
    category TEXT NOT NULL,
    tag_id INTEGER NOT NULL DEFAULT 1,
    card_id INTEGER NULL,
    is_split INTEGER NOT NULL DEFAULT 0,
    notes TEXT NULL,
    source TEXT NOT NULL DEFAULT "manual",
    import_fingerprint TEXT NULL,
    csv_import_run_id INTEGER NULL,
    created_at TEXT NULL,
    updated_at TEXT NULL,
    deleted_at TEXT NULL
)');
$closeoutPdo->exec('CREATE TABLE monthly_closeouts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    closeout_id TEXT NOT NULL,
    user_id INTEGER NOT NULL,
    month TEXT NOT NULL,
    status TEXT NOT NULL,
    result_type TEXT NOT NULL,
    budget_effective_month TEXT NULL,
    budget_allocation_mode TEXT NOT NULL,
    monthly_income_snapshot TEXT NOT NULL,
    planned_needs TEXT NOT NULL,
    planned_wants TEXT NOT NULL,
    planned_savings TEXT NOT NULL,
    planned_total TEXT NOT NULL,
    actual_needs TEXT NOT NULL,
    actual_wants TEXT NOT NULL,
    actual_savings TEXT NOT NULL,
    actual_total TEXT NOT NULL,
    surplus_amount TEXT NOT NULL,
    deficit_amount TEXT NOT NULL,
    spending_surplus_amount TEXT NOT NULL,
    spending_deficit_amount TEXT NOT NULL,
    calculation_hash TEXT NOT NULL,
    notes TEXT NULL,
    closed_at TEXT NOT NULL,
    reopened_at TEXT NULL,
    created_at TEXT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NULL DEFAULT CURRENT_TIMESTAMP
)');
$closeoutPdo->exec('CREATE TABLE monthly_closeout_allocations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    allocation_id TEXT NOT NULL,
    closeout_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    allocation_type TEXT NOT NULL,
    fund_id INTEGER NULL,
    label TEXT NULL,
    amount TEXT NOT NULL,
    target_month TEXT NULL,
    notes TEXT NULL,
    created_at TEXT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NULL DEFAULT CURRENT_TIMESTAMP
)');
$closeoutPdo->exec('CREATE TABLE fund_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fund_entry_id TEXT NOT NULL,
    user_id INTEGER NOT NULL,
    fund_id INTEGER NOT NULL,
    entry_date TEXT NOT NULL,
    entry_type TEXT NOT NULL,
    direction TEXT NOT NULL,
    amount TEXT NOT NULL,
    source_type TEXT NOT NULL,
    source_transaction_id INTEGER NULL,
    source_closeout_id INTEGER NULL,
    source_closeout_allocation_id INTEGER NULL,
    note TEXT NULL,
    voided_at TEXT NULL,
    void_reason TEXT NULL,
    deleted_at TEXT NULL,
    created_at TEXT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NULL DEFAULT CURRENT_TIMESTAMP
)');
$closeoutPdo->exec("INSERT INTO tags (id, user_id, name, icon_key, is_active, deleted_at, created_at, updated_at) VALUES (1, 11, 'Travel', 'plane', 1, NULL, '2026-06-01 00:00:00', '2026-06-01 00:00:00')");

$insertCloseoutBudgetVersion = static function (
    int $userId,
    string $effectiveMonth,
    string $monthlyIncome,
    string $allocationMode,
    ?string $needsPercent,
    ?string $wantsPercent,
    ?string $savingsPercent,
    ?string $needsAmount,
    ?string $wantsAmount,
    ?string $savingsAmount
) use ($closeoutPdo): void {
    $stmt = $closeoutPdo->prepare('
        INSERT INTO budget_settings_versions (
            user_id, effective_month, monthly_income, income_source_type,
            primary_monthly_income, primary_hourly_rate, primary_weekly_hours,
            side_income_type, side_income_label, side_monthly_income, side_hourly_rate, side_weekly_hours,
            allocation_mode, needs_percent, wants_percent, savings_percent, needs_amount, wants_amount, savings_amount,
            created_at, updated_at
        ) VALUES (
            :user_id, :effective_month, :monthly_income, :income_source_type,
            :primary_monthly_income, :primary_hourly_rate, :primary_weekly_hours,
            :side_income_type, :side_income_label, :side_monthly_income, :side_hourly_rate, :side_weekly_hours,
            :allocation_mode, :needs_percent, :wants_percent, :savings_percent, :needs_amount, :wants_amount, :savings_amount,
            :created_at, :updated_at
        )
    ');
    $stmt->execute([
        ':user_id' => $userId,
        ':effective_month' => $effectiveMonth,
        ':monthly_income' => $monthlyIncome,
        ':income_source_type' => 'monthly',
        ':primary_monthly_income' => $monthlyIncome,
        ':primary_hourly_rate' => null,
        ':primary_weekly_hours' => null,
        ':side_income_type' => 'none',
        ':side_income_label' => null,
        ':side_monthly_income' => null,
        ':side_hourly_rate' => null,
        ':side_weekly_hours' => null,
        ':allocation_mode' => $allocationMode,
        ':needs_percent' => $needsPercent,
        ':wants_percent' => $wantsPercent,
        ':savings_percent' => $savingsPercent,
        ':needs_amount' => $needsAmount,
        ':wants_amount' => $wantsAmount,
        ':savings_amount' => $savingsAmount,
        ':created_at' => '2026-06-01 00:00:00',
        ':updated_at' => '2026-06-01 00:00:00',
    ]);
};
$insertCloseoutTransaction = static function (
    int $userId,
    string $date,
    string $amount,
    string $category,
    string $expense = 'Test'
) use ($closeoutPdo): void {
    $stmt = $closeoutPdo->prepare('
        INSERT INTO transactions (
            user_id, transaction_date, expense, amount, category, tag_id, card_id,
            is_split, notes, source, import_fingerprint, csv_import_run_id, created_at, updated_at, deleted_at
        ) VALUES (
            :user_id, :transaction_date, :expense, :amount, :category, 1, NULL,
            0, NULL, "manual", NULL, NULL, "2026-06-01 00:00:00", "2026-06-01 00:00:00", NULL
        )
    ');
    $stmt->execute([
        ':user_id' => $userId,
        ':transaction_date' => $date,
        ':expense' => $expense,
        ':amount' => $amount,
        ':category' => $category,
    ]);
};

$closeoutConfig = $configReflection->newInstanceWithoutConstructor();
$valuesProperty->setValue($closeoutConfig, ['APP_TIMEZONE' => 'UTC']);
$closeoutResolver = new BudgetSettingsResolver($closeoutPdo);
$closeoutRepository = new MonthCloseoutRepository($closeoutPdo);
$fundRepository = new FundRepository($closeoutPdo);
$fundBalanceService = new FundBalanceService($fundRepository);
$fundTransactionIntegrationService = new FundTransactionIntegrationService($closeoutPdo, $fundRepository);
$fundCloseoutIntegrationService = new FundCloseoutIntegrationService($closeoutPdo, $fundRepository);
$fundService = new FundService($closeoutPdo, $fundRepository, $fundBalanceService, $fundTransactionIntegrationService);
$closeoutService = new MonthCloseoutService($closeoutPdo, $closeoutConfig, $closeoutResolver, $closeoutRepository, $fundCloseoutIntegrationService);

$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$currentCloseoutMonth = $nowUtc->format('Y-m');
$pastCloseoutMonth = $nowUtc->modify('-1 month')->format('Y-m');
$futureCloseoutMonth = $nowUtc->modify('+1 month')->format('Y-m');
$pastCloseoutMonthStart = $pastCloseoutMonth . '-01';
$currentCloseoutMonthStart = $currentCloseoutMonth . '-01';

$insertCloseoutBudgetVersion(11, $pastCloseoutMonthStart, '1000.00', 'percent', '50.00', '30.00', '20.00', null, null, null);
$insertCloseoutBudgetVersion(11, $currentCloseoutMonthStart, '1000.00', 'percent', '50.00', '30.00', '20.00', null, null, null);
$insertCloseoutTransaction(11, $pastCloseoutMonth . '-05', '400.00', 'needs', 'Rent share');
$insertCloseoutTransaction(11, $pastCloseoutMonth . '-10', '100.00', 'wants', 'Dining');
$insertCloseoutTransaction(11, $pastCloseoutMonth . '-12', '50.00', 'savings', 'Transfer');
$insertCloseoutTransaction(11, $currentCloseoutMonth . '-02', '20.00', 'needs', 'Current month spend');

$computedPastCloseout = $closeoutService->getMonthCloseout(11, $pastCloseoutMonth);
assertSame('ready_to_close', $computedPastCloseout['status'], 'month closeout marks past month ready to close');
assertSame(true, $computedPastCloseout['is_closeable'], 'month closeout marks past month closeable');
assertSame('surplus', $computedPastCloseout['computed']['result_type'], 'month closeout computes surplus result');
assertSame('450.00', $computedPastCloseout['computed']['surplus_amount'], 'month closeout computes surplus amount');
assertSame('300.00', $computedPastCloseout['computed']['spending_surplus_amount'], 'month closeout computes spending-only surplus');

$currentCloseout = $closeoutService->getMonthCloseout(11, $currentCloseoutMonth);
assertSame('open', $currentCloseout['status'], 'month closeout keeps current month open');
assertSame(false, $currentCloseout['is_closeable'], 'month closeout rejects closing current month');

$futureCloseout = $closeoutService->computeMonthResult(11, $futureCloseoutMonth);
assertSame('future', $futureCloseout['status'], 'month closeout marks future month as future');
assertSame(false, $futureCloseout['is_closeable'], 'month closeout rejects future month');

$missingBudgetCloseout = $closeoutService->getMonthCloseout(404, $pastCloseoutMonth);
assertSame('missing_budget', $missingBudgetCloseout['status'], 'month closeout reports missing budget');
assertSame(null, $missingBudgetCloseout['computed'], 'month closeout omits computed payload when budget is missing');

$closedPastCloseout = $closeoutService->closeMonth(11, $pastCloseoutMonth, [
    'notes' => 'Good month.',
    'allocations' => [
        [
            'allocation_type' => 'savings',
            'label' => 'HYSA',
            'amount' => '300.00',
        ],
    ],
]);
assertSame('closed', $closedPastCloseout['status'], 'close month returns closed status');
assertSame('300.00', $closedPastCloseout['closeout']['allocated_amount'], 'close month stores allocated amount');
assertSame('150.00', $closedPastCloseout['closeout']['unallocated_amount'], 'close month stores unallocated amount');
assertSame('Good month.', $closedPastCloseout['closeout']['notes'], 'close month stores notes');
assertSame(false, $closedPastCloseout['closeout']['is_stale'], 'newly closed month is not stale');

expectHttpException(
    fn() => $closeoutService->closeMonth(11, $pastCloseoutMonth, [
        'allocations' => [
            [
                'allocation_type' => 'covered_by_buffer',
                'amount' => '10.00',
            ],
        ],
    ]),
    422,
    'VALIDATION_ERROR',
    'surplus closeout rejects deficit-only allocation type'
);
expectHttpException(
    fn() => $closeoutService->closeMonth(11, $pastCloseoutMonth, [
        'allocations' => [
            [
                'allocation_type' => 'rollover',
                'amount' => '10.00',
            ],
        ],
    ]),
    422,
    'VALIDATION_ERROR',
    'rollover allocation requires target month'
);
expectHttpException(
    fn() => $closeoutService->closeMonth(11, $currentCloseoutMonth, []),
    422,
    'VALIDATION_ERROR',
    'current month cannot be closed'
);

$insertCloseoutTransaction(11, $pastCloseoutMonth . '-18', '25.00', 'needs', 'Late transaction');
$stalePastCloseout = $closeoutService->getMonthCloseout(11, $pastCloseoutMonth);
assertSame(true, $stalePastCloseout['closeout']['is_stale'], 'closeout becomes stale after historical transaction changes');
assertSame(['calculation_changed'], $stalePastCloseout['closeout']['stale_reasons'], 'closeout returns stale reason');

$patchedPastCloseout = $closeoutService->updateCloseout(11, $pastCloseoutMonth, [
    'notes' => 'Updated note.',
    'allocations' => [
        [
            'allocation_type' => 'savings',
            'label' => 'HYSA',
            'amount' => '250.00',
        ],
        [
            'allocation_type' => 'buffer',
            'label' => 'Checking',
            'amount' => '50.00',
        ],
    ],
]);
assertSame('Updated note.', $patchedPastCloseout['closeout']['notes'], 'patch updates notes');
assertSame('300.00', $patchedPastCloseout['closeout']['allocated_amount'], 'patch replaces allocations');
assertSame(true, $patchedPastCloseout['closeout']['is_stale'], 'patch preserves stale status when calculation changed');

$reopenedPastCloseout = $closeoutService->reopenMonth(11, $pastCloseoutMonth);
assertSame('reopened', $reopenedPastCloseout['status'], 'reopen marks closeout reopened');
assertSame(null !== $reopenedPastCloseout['closeout']['reopened_at'], true, 'reopen records reopened timestamp');
expectHttpException(
    fn() => $closeoutService->updateCloseout(11, $pastCloseoutMonth, ['notes' => 'Nope']),
    409,
    'CONFLICT',
    'patch rejects reopened closeout'
);

$reclosedPastCloseout = $closeoutService->closeMonth(11, $pastCloseoutMonth, [
    'notes' => 'Reclosed.',
    'allocations' => [
        [
            'allocation_type' => 'savings',
            'amount' => '200.00',
        ],
    ],
]);
assertSame('closed', $reclosedPastCloseout['status'], 'reclose closes reopened month again');
assertSame(false, $reclosedPastCloseout['closeout']['is_stale'], 'reclose refreshes stale closeout');

$closeoutList = $closeoutService->listMonthCloseouts(11, []);
assertSame(1, count($closeoutList['items']), 'list closeouts returns saved closeout');
assertSame('200.00', $closeoutList['items'][0]['allocated_amount'], 'list closeouts includes allocated amount');

$createdFund = $fundService->createFund(11, [
    'name' => 'Japan 2026',
    'goal_amount' => '5000.00',
    'target_month' => $futureCloseoutMonth,
    'starting_balance' => '300.00',
]);
assertSame('300.00', $createdFund['current_balance'], 'create fund stores starting balance as fund entry');
assertSame('Japan 2026', $createdFund['name'], 'create fund stores name');
assertSame(1, $createdFund['entries_count'], 'create fund exposes starting balance entry count');
assertSame('goal', $createdFund['fund_type'], 'create fund keeps default internal fund type for compatibility');

$openEndedFund = $fundService->createFund(11, [
    'name' => 'Open Ended',
    'starting_balance' => '25.00',
]);
assertSame(null, $openEndedFund['goal_amount'], 'create fund supports open-ended funds without goal amount');
assertSame(null, $openEndedFund['target_month'], 'open-ended fund has no target month');

$zeroStartingBalanceFund = $fundService->createFund(11, [
    'name' => 'Zero Balance',
    'starting_balance' => '0.00',
]);
assertSame('0.00', $zeroStartingBalanceFund['current_balance'], 'create fund accepts explicit zero starting balance');
assertSame(0, $zeroStartingBalanceFund['entries_count'], 'zero starting balance does not create a ledger entry');

expectHttpException(
    fn() => $fundService->createFund(11, [
        'name' => 'Invalid Target',
        'target_month' => $futureCloseoutMonth,
    ]),
    422,
    'VALIDATION_ERROR',
    'create fund rejects target month without goal amount'
);

$goalRemovedFund = $fundService->updateFund(11, $createdFund['id'], [
    'goal_amount' => null,
]);
assertSame(null, $goalRemovedFund['goal_amount'], 'update fund removes goal amount');
assertSame(null, $goalRemovedFund['target_month'], 'update fund clears target month when removing goal');

$createdFund = $fundService->updateFund(11, $createdFund['id'], [
    'goal_amount' => '5000.00',
    'target_month' => $futureCloseoutMonth,
]);
assertSame('5000.00', $createdFund['goal_amount'], 'update fund can restore goal amount');
assertSame($futureCloseoutMonth, $createdFund['target_month'], 'update fund can restore target month with goal');

$manualEntry = $fundService->createEntry(11, $createdFund['id'], [
    'entry_date' => $pastCloseoutMonth . '-15',
    'entry_type' => 'contribution',
    'direction' => 'in',
    'amount' => '50.00',
    'source_type' => 'manual',
    'budget_tracking' => 'fund_only',
    'note' => 'Cash saved',
]);
assertSame('manual', $manualEntry['source_type'], 'manual fund entry keeps manual source type');

$transactionEntry = $fundService->createEntry(11, $createdFund['id'], [
    'entry_date' => $pastCloseoutMonth . '-16',
    'entry_type' => 'contribution',
    'direction' => 'in',
    'amount' => '200.00',
    'source_type' => 'transaction',
    'budget_tracking' => 'create_transaction',
    'transaction' => [
        'expense' => 'Japan 2026 contribution',
        'tag_id' => '1',
        'card_id' => null,
        'notes' => 'Transfer to Japan fund',
    ],
]);
assertSame('transaction', $transactionEntry['source_type'], 'create_transaction creates transaction-linked fund entry');
assertSame(1, (int) $closeoutPdo->query("SELECT COUNT(*) AS total FROM transactions WHERE user_id = 11 AND expense = 'Japan 2026 contribution' AND category = 'savings'")->fetch()['total'], 'create_transaction inserts savings transaction');

$linkedTransactionId = (int) $closeoutPdo->query("SELECT id FROM transactions WHERE user_id = 11 AND expense = 'Japan 2026 contribution' ORDER BY id DESC LIMIT 1")->fetch()['id'];
$linkedEntryRow = $fundRepository->findActiveEntryByTransactionId(11, $linkedTransactionId);
assertSame(true, $linkedEntryRow !== null, 'create_transaction links active fund entry to transaction');

$closeoutPdo->exec("INSERT INTO transactions (user_id, transaction_date, expense, amount, category, is_split, notes, tag_id, card_id, source) VALUES (11, '{$pastCloseoutMonth}-17', 'Extra Japan contribution', '25.00', 'savings', 0, 'Manual savings transfer', 1, NULL, 'manual')");
$unlinkedSavingsTransactionId = (int) $closeoutPdo->lastInsertId();

$linkedExistingEntry = $fundService->createEntry(11, $createdFund['id'], [
    'entry_date' => $pastCloseoutMonth . '-17',
    'entry_type' => 'contribution',
    'direction' => 'in',
    'amount' => '25.00',
    'source_type' => 'manual',
    'budget_tracking' => 'link_existing_transaction',
    'transaction_id' => (string) $unlinkedSavingsTransactionId,
    'note' => 'Linked from existing savings transaction',
]);
assertSame('transaction', $linkedExistingEntry['source_type'], 'link_existing_transaction creates transaction-linked fund entry even when payload source_type is manual');
assertSame((string) $unlinkedSavingsTransactionId, $linkedExistingEntry['source_transaction_id'], 'link_existing_transaction stores linked transaction id');

$closeWithFundAllocation = $closeoutService->closeMonth(11, $pastCloseoutMonth, [
    'notes' => 'Funded trip.',
    'allocations' => [
        [
            'allocation_type' => 'fund',
            'fund_id' => $createdFund['id'],
            'amount' => '150.00',
            'notes' => 'June surplus to trip',
        ],
    ],
]);
assertSame('150.00', $closeWithFundAllocation['closeout']['allocated_amount'], 'closeout with fund allocation stores allocated amount');
assertSame('fund', $closeWithFundAllocation['closeout']['allocations'][0]['allocation_type'], 'closeout serializes fund allocation type');
assertSame($createdFund['id'], $closeWithFundAllocation['closeout']['allocations'][0]['fund_id'], 'closeout serializes allocated fund id');

$fundAfterCloseout = $fundService->getFund(11, $createdFund['id']);
assertSame('725.00', $fundAfterCloseout['current_balance'], 'closeout-linked fund entry increases fund balance');
assertSame('150.00', $fundAfterCloseout['source_breakdown']['month_closeout'], 'fund source breakdown tracks closeout contributions');

$patchedWithFundAllocation = $closeoutService->updateCloseout(11, $pastCloseoutMonth, [
    'notes' => 'Retargeted surplus.',
    'allocations' => [
        [
            'allocation_type' => 'fund',
            'fund_id' => $createdFund['id'],
            'amount' => '125.00',
            'notes' => 'Adjusted closeout contribution',
        ],
    ],
]);
assertSame('125.00', $patchedWithFundAllocation['closeout']['allocated_amount'], 'patch closeout replaces fund allocation amount');
$fundAfterPatch = $fundService->getFund(11, $createdFund['id']);
assertSame('700.00', $fundAfterPatch['current_balance'], 'patching closeout voids old fund-linked entry and creates replacement');

$reopenedWithFundEntry = $closeoutService->reopenMonth(11, $pastCloseoutMonth);
assertSame('reopened', $reopenedWithFundEntry['status'], 'reopen still returns reopened status with fund entries present');
$fundAfterReopen = $fundService->getFund(11, $createdFund['id']);
assertSame('575.00', $fundAfterReopen['current_balance'], 'reopen voids closeout-linked fund entry from active balance');

$reclosedWithFundAllocation = $closeoutService->closeMonth(11, $pastCloseoutMonth, [
    'notes' => 'Reclosed to fund.',
    'allocations' => [
        [
            'allocation_type' => 'fund',
            'fund_id' => $createdFund['id'],
            'amount' => '100.00',
        ],
    ],
]);
assertSame('closed', $reclosedWithFundAllocation['status'], 'reclose works with fund allocation');
$fundAfterReclose = $fundService->getFund(11, $createdFund['id']);
assertSame('675.00', $fundAfterReclose['current_balance'], 'reclose adds new active closeout-linked entry');

$closeoutSummary = $fundService->closeoutSummary(11, (int) substr($pastCloseoutMonth, 0, 4));
assertSame('100.00', $closeoutSummary['total_closeout_contributed'], 'closeout summary totals active closeout-linked contributions');
assertSame(1, count($closeoutSummary['funds']), 'closeout summary groups contributions by fund');

$closeoutPdo->prepare("UPDATE transactions SET amount = '250.00', notes = 'Updated linked note' WHERE id = :id")->execute([':id' => $linkedTransactionId]);
$fundTransactionIntegrationService->syncLinkedTransactionUpdate(
    11,
    ['notes' => 'Transfer to Japan fund'],
    $linkedEntryRow,
    $pastCloseoutMonth . '-16',
    '250.00',
    'savings',
    'Updated linked note'
);
$fundAfterTxnSync = $fundService->getFund(11, $createdFund['id']);
assertSame('725.00', $fundAfterTxnSync['current_balance'], 'transaction sync updates linked fund entry amount');

$fundTransactionIntegrationService->voidLinkedTransactionDelete(11, $linkedTransactionId, gmdate('Y-m-d H:i:s'));
$fundAfterTxnDelete = $fundService->getFund(11, $createdFund['id']);
assertSame('475.00', $fundAfterTxnDelete['current_balance'], 'deleting linked transaction voids linked fund entry');

fwrite(STDOUT, "Backend core tests passed\n");

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($actual !== $expected) {
        fail(sprintf(
            '%s: expected %s, got %s',
            $label,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertMatches(string $pattern, string $actual, string $label): void
{
    if (preg_match($pattern, $actual) !== 1) {
        fail(sprintf('%s: expected %s to match %s', $label, var_export($actual, true), $pattern));
    }
}

function assertTrue(bool $condition, string $label): void
{
    if (!$condition) {
        fail($label);
    }
}

function expectHttpException(callable $callback, int $status, string $code, string $label): void
{
    try {
        $callback();
    } catch (HttpException $e) {
        assertSame($status, $e->status, $label . ' status');
        assertSame($code, $e->errorCode, $label . ' code');
        return;
    }

    fail($label . ': expected HttpException');
}

function fail(string $message): never
{
    fwrite(STDERR, "Backend core tests failed: {$message}\n");
    exit(1);
}
