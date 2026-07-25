<?php

declare(strict_types=1);

use App\Auth\AuthService;
use App\Controllers\TaxonomyController;
use App\Controllers\TransactionController;
use App\Funds\FundRepository;
use App\Funds\FundTransactionIntegrationService;
use App\Http\Request;
use App\Recurring\RecurringExpenseService;
use App\Support\Str;

require __DIR__ . '/../src/bootstrap.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec('CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    email TEXT NOT NULL,
    display_name TEXT,
    avatar_url TEXT,
    user_preferences TEXT,
    auth_provider TEXT,
    email_verified INTEGER,
    role TEXT,
    created_at TEXT,
    is_active INTEGER NOT NULL DEFAULT 1
)');
$pdo->exec('CREATE TABLE user_sessions (
    session_id TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL,
    session_secret_hash TEXT NOT NULL,
    csrf_token_hash TEXT,
    last_seen_at TEXT,
    revoked_at TEXT,
    expires_at TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE tags (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    icon_key TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    deleted_at TEXT
)');
$pdo->exec('CREATE TABLE cards (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    is_favorite INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    deleted_at TEXT
)');
$pdo->exec('CREATE TABLE contexts (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    icon_key TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    deleted_at TEXT,
    updated_at TEXT
)');
$pdo->exec('CREATE TABLE recurring_expense_occurrences (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    recurring_expense_id INTEGER NOT NULL,
    occurrence_month TEXT NOT NULL,
    due_date TEXT NOT NULL,
    transaction_id INTEGER
)');
$pdo->exec('CREATE TABLE recurring_expenses (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE transactions (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    transaction_date TEXT NOT NULL,
    expense TEXT NOT NULL,
    amount TEXT NOT NULL,
    category TEXT NOT NULL,
    tag_id INTEGER NOT NULL,
    context_id INTEGER,
    card_id INTEGER,
    is_split INTEGER NOT NULL DEFAULT 0,
    notes TEXT,
    source TEXT NOT NULL DEFAULT "manual",
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT
)');
$pdo->exec('CREATE TABLE funds (
    id INTEGER PRIMARY KEY,
    fund_id TEXT NOT NULL,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    fund_type TEXT NOT NULL,
    goal_amount TEXT,
    target_month TEXT,
    notes TEXT,
    status TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    archived_at TEXT,
    created_at TEXT,
    updated_at TEXT
)');
$pdo->exec('CREATE TABLE fund_entries (
    id INTEGER PRIMARY KEY,
    fund_entry_id TEXT NOT NULL,
    user_id INTEGER NOT NULL,
    fund_id INTEGER NOT NULL,
    entry_date TEXT NOT NULL,
    entry_type TEXT NOT NULL,
    direction TEXT NOT NULL,
    amount TEXT NOT NULL,
    source_type TEXT NOT NULL,
    source_transaction_id INTEGER,
    source_closeout_id INTEGER,
    source_closeout_allocation_id INTEGER,
    note TEXT,
    voided_at TEXT,
    void_reason TEXT,
    deleted_at TEXT,
    updated_at TEXT
)');
$pdo->exec('CREATE TABLE monthly_closeouts (id INTEGER PRIMARY KEY, closeout_id TEXT)');

$pdo->exec("INSERT INTO users (id, email, display_name, auth_provider, email_verified, role, created_at) VALUES (7, 'test@example.com', 'Test', 'password', 1, 'owner', CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO user_sessions (session_id, user_id, session_secret_hash, expires_at) VALUES ('sess_atomic', 7, '" . Str::hashSha256('secret') . "', datetime('now', '+1 day'))");
$pdo->exec("INSERT INTO tags (id, user_id, name) VALUES (1, 7, 'Savings')");
$pdo->exec("INSERT INTO funds (id, fund_id, user_id, name, fund_type, status) VALUES (1, 'fund_test', 7, 'Test Fund', 'goal', 'active')");
$pdo->exec("INSERT INTO transactions (id, user_id, transaction_date, expense, amount, category, tag_id, notes, created_at, updated_at) VALUES (1, 7, '2026-07-10', 'Original transfer', '100.00', 'savings', 1, 'Original note', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO fund_entries (id, fund_entry_id, user_id, fund_id, entry_date, entry_type, direction, amount, source_type, source_transaction_id, note, updated_at) VALUES (1, 'entry_test', 7, 1, '2026-07-10', 'contribution', 'in', '100.00', 'transaction', 1, 'Original note', CURRENT_TIMESTAMP)");

$config = App\Core\Config::load(dirname(__DIR__));
$auth = new AuthService($pdo, $config);
$repository = new FundRepository($pdo);
$controller = new TransactionController(
    $pdo,
    $auth,
    new RecurringExpenseService($pdo),
    new FundTransactionIntegrationService($pdo, $repository)
);
$contextController = new TaxonomyController($pdo, $auth);

$request = static function (string $method, array $payload = []): Request {
    return new Request(
        strtoupper($method),
        '/me/transactions/1',
        $payload === [] ? '' : json_encode($payload, JSON_THROW_ON_ERROR),
        [],
        [],
        [],
        [],
        ['Authorization' => 'Session sess_atomic.secret']
    );
};

$contextRequest = static function (array $payload): Request {
    return new Request(
        'POST',
        '/me/contexts',
        json_encode($payload, JSON_THROW_ON_ERROR),
        [],
        [],
        [],
        [],
        ['Authorization' => 'Session sess_atomic.secret']
    );
};

$contextIds = [];
foreach (App\Support\ContextIconKeys::all() as $index => $contextIconKey) {
    $response = $contextController->createContext($contextRequest([
        'name' => 'Context ' . $index,
        'icon_key' => $contextIconKey,
    ]));
    $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
    assertSame($contextIconKey, $decoded['icon_key'], 'context creation preserves allowed icon ' . $contextIconKey);
    $contextIds[] = (string) $decoded['id'];
}
$nullContext = $contextController->createContext($contextRequest(['name' => 'Null Context', 'icon_key' => null]));
assertSame(null, json_decode($nullContext->body, true, 512, JSON_THROW_ON_ERROR)['icon_key'], 'context creation accepts null icon');
expectFailure(fn() => $contextController->createContext($contextRequest(['name' => 'Invalid Context', 'icon_key' => 'not-a-context-icon'])), 'invalid context icon is rejected by endpoint');
$updatedContext = $contextController->updateContext(
    new Request('PATCH', '/me/contexts/' . $contextIds[0], json_encode(['name' => 'Updated Context', 'icon_key' => 'box'], JSON_THROW_ON_ERROR), [], [], [], [], ['Authorization' => 'Session sess_atomic.secret']),
    ['context_id' => $contextIds[0]]
);
assertSame('box', json_decode($updatedContext->body, true, 512, JSON_THROW_ON_ERROR)['icon_key'], 'context update preserves allowed icon');
$pdo->exec("UPDATE contexts SET is_active = 0, deleted_at = CURRENT_TIMESTAMP WHERE id = " . (int) $contextIds[0]);
$reactivatedContext = $contextController->createContext($contextRequest(['name' => 'Updated Context', 'icon_key' => 'map_pinned']));
assertSame('map_pinned', json_decode($reactivatedContext->body, true, 512, JSON_THROW_ON_ERROR)['icon_key'], 'soft-deleted context reactivation preserves icon');

$pdo->exec("CREATE TRIGGER fail_fund_sync_update BEFORE UPDATE OF entry_date, amount, note ON fund_entries BEGIN SELECT RAISE(ABORT, 'forced fund sync failure'); END");
expectFailure(
    fn() => $controller->update($request('PATCH', [
        'date' => '2026-07-11',
        'amount' => '125.00',
        'notes' => 'Should roll back',
    ]), ['transaction_id' => '1']),
    'linked transaction update fails'
);
assertRow($pdo, "SELECT transaction_date, amount, notes FROM transactions WHERE id = 1", ['2026-07-10', '100.00', 'Original note'], 'update rollback restores transaction');
assertRow($pdo, "SELECT entry_date, amount, note, voided_at FROM fund_entries WHERE id = 1", ['2026-07-10', '100.00', 'Original note', null], 'update rollback restores fund entry');

$pdo->exec('DROP TRIGGER fail_fund_sync_update');
$controller->update($request('PATCH', [
    'date' => '2026-07-11',
    'amount' => '125.00',
    'notes' => 'Updated successfully',
]), ['transaction_id' => '1']);
assertRow($pdo, "SELECT transaction_date, amount, notes FROM transactions WHERE id = 1", ['2026-07-11', '125.00', 'Updated successfully'], 'successful update persists transaction');
assertRow($pdo, "SELECT entry_date, amount, note FROM fund_entries WHERE id = 1", ['2026-07-11', '125.00', 'Updated successfully'], 'successful update persists fund entry');

$pdo->exec("CREATE TRIGGER fail_fund_sync_delete BEFORE UPDATE OF voided_at, void_reason ON fund_entries BEGIN SELECT RAISE(ABORT, 'forced fund delete sync failure'); END");
expectFailure(fn() => $controller->delete($request('DELETE'), ['transaction_id' => '1']), 'linked transaction delete fails');
assertRow($pdo, "SELECT deleted_at FROM transactions WHERE id = 1", [null], 'delete rollback restores transaction');
assertRow($pdo, "SELECT voided_at, void_reason FROM fund_entries WHERE id = 1", [null, null], 'delete rollback restores fund entry');

$pdo->exec('DROP TRIGGER fail_fund_sync_delete');
$controller->delete($request('DELETE'), ['transaction_id' => '1']);
assertRow($pdo, "SELECT deleted_at FROM transactions WHERE id = 1", [true], 'successful delete soft-deletes transaction');
assertRow($pdo, "SELECT voided_at, void_reason FROM fund_entries WHERE id = 1", [true, 'transaction_deleted'], 'successful delete voids linked fund entry');
assertSame('0.00', number_format((float) $pdo->query("SELECT COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0.00) FROM fund_entries WHERE fund_id = 1 AND deleted_at IS NULL AND voided_at IS NULL")->fetchColumn(), 2, '.', ''), 'voided contribution no longer affects fund balance');

fwrite(STDOUT, "Transaction atomicity smoke test passed\n");

function expectFailure(callable $operation, string $label): void
{
    try {
        $operation();
    } catch (Throwable) {
        return;
    }

    throw new RuntimeException($label . ': expected operation to fail');
}

function assertRow(PDO $pdo, string $sql, array $expected, string $label): void
{
    $row = $pdo->query($sql)->fetch(PDO::FETCH_NUM);
    if ($row === false) {
        throw new RuntimeException($label . ': row not found');
    }

    foreach ($expected as $index => $value) {
        if ($value === true) {
            if ($row[$index] === null) {
                throw new RuntimeException($label . ': expected non-null value');
            }
            continue;
        }
        if ($row[$index] !== $value) {
            throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($row, true));
        }
    }
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
