<?php

declare(strict_types=1);

use App\Auth\AuthService;
use App\Controllers\TaxonomyController;
use App\Controllers\TransactionController;
use App\Core\Config;
use App\Funds\FundRepository;
use App\Funds\FundTransactionIntegrationService;
use App\Http\HttpException;
use App\Http\Request;
use App\Recurring\RecurringExpenseService;
use App\Support\Str;

require __DIR__ . '/../src/bootstrap.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$previousErrorReporting = error_reporting();
error_reporting($previousErrorReporting & ~E_DEPRECATED);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn(): string => gmdate('Y-m-d H:i:s'));
error_reporting($previousErrorReporting);

$pdo->exec('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    display_name TEXT NOT NULL,
    avatar_url TEXT NULL,
    user_preferences TEXT NULL,
    auth_provider TEXT NOT NULL,
    password_hash TEXT NULL,
    google_sub TEXT NULL,
    email_verified INTEGER NOT NULL DEFAULT 1,
    role TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE master_api_keys (
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
$pdo->exec('CREATE TABLE cards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    is_favorite INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    deleted_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec('CREATE UNIQUE INDEX uq_cards_user_name ON cards (user_id, name)');
$pdo->exec('CREATE TABLE tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    icon_key TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    deleted_at TEXT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec('CREATE TABLE funds (
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
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec('CREATE TABLE fund_entries (
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
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec('CREATE TABLE transactions (
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
    source TEXT NOT NULL DEFAULT "manual",
    recurring_expense_id INTEGER NULL,
    deleted_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec('CREATE TABLE recurring_expense_occurrences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    recurring_expense_id INTEGER NOT NULL,
    occurrence_month TEXT NOT NULL,
    due_date TEXT NOT NULL,
    transaction_id INTEGER NULL
)');
$pdo->exec('CREATE TABLE recurring_expenses (
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

$pdo->exec("INSERT INTO users (id, email, display_name, avatar_url, user_preferences, auth_provider, email_verified, role, is_active, created_at) VALUES
    (1, 'owner1@example.com', 'Owner One', NULL, NULL, 'password', 1, 'owner', 1, '2026-06-19 00:00:00'),
    (2, 'owner2@example.com', 'Owner Two', NULL, NULL, 'password', 1, 'owner', 1, '2026-06-19 00:00:00')
");

$apiKeyUser1 = 'test_user1_key';
$apiKeyUser2 = 'test_user2_key';
$insertApiKey = $pdo->prepare('INSERT INTO master_api_keys (key_id, user_id, name, key_prefix, key_hash, is_active, created_at) VALUES (:key_id, :user_id, :name, :key_prefix, :key_hash, 1, CURRENT_TIMESTAMP)');
$insertApiKey->execute([
    ':key_id' => 'mak_1',
    ':user_id' => 1,
    ':name' => 'user1',
    ':key_prefix' => 'bgtm_user1',
    ':key_hash' => Str::hashSha256($apiKeyUser1),
]);
$insertApiKey->execute([
    ':key_id' => 'mak_2',
    ':user_id' => 2,
    ':name' => 'user2',
    ':key_prefix' => 'bgtm_user2',
    ':key_hash' => Str::hashSha256($apiKeyUser2),
]);

$insertCard = $pdo->prepare('INSERT INTO cards (id, user_id, name, is_favorite, is_active, deleted_at, updated_at) VALUES (:id, :user_id, :name, :is_favorite, :is_active, :deleted_at, CURRENT_TIMESTAMP)');
$insertCard->execute([':id' => 1, ':user_id' => 1, ':name' => 'Zeta', ':is_favorite' => 0, ':is_active' => 1, ':deleted_at' => null]);
$insertCard->execute([':id' => 2, ':user_id' => 1, ':name' => 'Alpha Card', ':is_favorite' => 1, ':is_active' => 1, ':deleted_at' => null]);
$insertCard->execute([':id' => 3, ':user_id' => 1, ':name' => 'beta card', ':is_favorite' => 0, ':is_active' => 1, ':deleted_at' => null]);
$insertCard->execute([':id' => 4, ':user_id' => 2, ':name' => 'Outside Card', ':is_favorite' => 1, ':is_active' => 1, ':deleted_at' => null]);

$pdo->exec("INSERT INTO tags (id, user_id, name, icon_key, is_active, deleted_at, updated_at) VALUES (1, 1, 'Coffee', 'coffee', 1, NULL, CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO transactions (user_id, transaction_date, expense, amount, category, is_split, notes, tag_id, card_id, source, deleted_at, created_at, updated_at) VALUES
    (1, '2026-06-10', 'Coffee Shop', '5.25', 'wants', 0, NULL, 1, 3, 'manual', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    (1, '2026-06-12', 'Coffee Shop', '5.75', 'wants', 0, NULL, 1, 3, 'manual', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    (1, '2026-06-14', 'Coffee Shop', '6.10', 'wants', 0, NULL, 1, 2, 'manual', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
");

$config = Config::load(sys_get_temp_dir());
$auth = new AuthService($pdo, $config);
$taxonomy = new TaxonomyController($pdo, $auth);
$recurringStub = (new ReflectionClass(RecurringExpenseService::class))->newInstanceWithoutConstructor();
$fundRepository = new FundRepository($pdo);
$fundTransactionIntegrationService = new FundTransactionIntegrationService($pdo, $fundRepository);
$transactions = new TransactionController($pdo, $auth, $recurringStub, $fundTransactionIntegrationService);

$listResponse = $taxonomy->listCards(apiKeyRequest('GET', '/me/cards', $apiKeyUser1));
$listPayload = decodeJsonBody($listResponse->body);
assertSame(['2', '3', '1'], array_map(static fn(array $item): string => $item['id'], $listPayload['items']), 'cards list orders favorite first, then name');
assertSame(true, $listPayload['items'][0]['is_favorite'], 'cards list serializes favorite field');
assertSame(false, $listPayload['items'][1]['is_favorite'], 'cards list serializes non-favorite field');

$favoriteResponse = $taxonomy->updateCard(
    apiKeyRequest('PATCH', '/me/cards/1', $apiKeyUser1, ['is_favorite' => true]),
    ['card_id' => '1']
);
$favoritePayload = decodeJsonBody($favoriteResponse->body);
assertSame('1', $favoritePayload['id'], 'favorite update returns selected card');
assertSame(true, $favoritePayload['is_favorite'], 'favorite update returns true');
assertSame('0', (string) $pdo->query('SELECT COALESCE(SUM(is_favorite), 0) AS total FROM cards WHERE user_id = 1 AND id <> 1 AND is_active = 1 AND deleted_at IS NULL')->fetch()['total'], 'favoriting clears previous active favorite');
assertSame('1', (string) $pdo->query('SELECT is_favorite FROM cards WHERE id = 1')->fetch()['is_favorite'], 'favoriting sets selected card');

$replaceFavoriteResponse = $taxonomy->updateCard(
    apiKeyRequest('PATCH', '/me/cards/3', $apiKeyUser1, ['is_favorite' => true]),
    ['card_id' => '3']
);
$replaceFavoritePayload = decodeJsonBody($replaceFavoriteResponse->body);
assertSame(true, $replaceFavoritePayload['is_favorite'], 'replacing favorite returns true');
assertSame('0', (string) $pdo->query('SELECT is_favorite FROM cards WHERE id = 1')->fetch()['is_favorite'], 'replacing favorite clears previous selected favorite');
assertSame('1', (string) $pdo->query('SELECT is_favorite FROM cards WHERE id = 3')->fetch()['is_favorite'], 'replacing favorite marks new card');

$clearFavoriteResponse = $taxonomy->updateCard(
    apiKeyRequest('PATCH', '/me/cards/3', $apiKeyUser1, ['is_favorite' => false]),
    ['card_id' => '3']
);
$clearFavoritePayload = decodeJsonBody($clearFavoriteResponse->body);
assertSame(false, $clearFavoritePayload['is_favorite'], 'clearing favorite returns false');
assertSame('0', (string) $pdo->query('SELECT COALESCE(SUM(is_favorite), 0) AS total FROM cards WHERE user_id = 1 AND is_active = 1 AND deleted_at IS NULL')->fetch()['total'], 'clearing favorite leaves no active favorite');

$renameFavoriteResponse = $taxonomy->updateCard(
    apiKeyRequest('PATCH', '/me/cards/1', $apiKeyUser1, ['name' => 'Atlas Card', 'is_favorite' => true]),
    ['card_id' => '1']
);
$renameFavoritePayload = decodeJsonBody($renameFavoriteResponse->body);
assertSame('Atlas Card', $renameFavoritePayload['name'], 'rename and favorite updates name');
assertSame(true, $renameFavoritePayload['is_favorite'], 'rename and favorite updates favorite');

expectHttpException(
    fn() => $taxonomy->updateCard(apiKeyRequest('PATCH', '/me/cards/1', $apiKeyUser1, []), ['card_id' => '1']),
    422,
    'VALIDATION_ERROR',
    'card update requires at least one field'
);
expectHttpException(
    fn() => $taxonomy->updateCard(apiKeyRequest('PATCH', '/me/cards/1', $apiKeyUser1, ['is_favorite' => 'yes']), ['card_id' => '1']),
    422,
    'VALIDATION_ERROR',
    'card update requires boolean is_favorite'
);
expectHttpException(
    fn() => $taxonomy->updateCard(apiKeyRequest('PATCH', '/me/cards/1', $apiKeyUser2, ['is_favorite' => true]), ['card_id' => '1']),
    404,
    'NOT_FOUND',
    'card update is scoped to current user'
);
expectHttpException(
    fn() => $taxonomy->updateCard(apiKeyRequest('PATCH', '/me/cards/1', $apiKeyUser1, ['name' => 'beta card']), ['card_id' => '1']),
    409,
    'CONFLICT',
    'card rename preserves duplicate-name validation'
);

$taxonomy->deleteCard(apiKeyRequest('DELETE', '/me/cards/1', $apiKeyUser1), ['card_id' => '1']);
$deletedCard = $pdo->query('SELECT is_active, is_favorite, deleted_at FROM cards WHERE id = 1')->fetch();
assertSame('0', (string) $deletedCard['is_active'], 'deleting card soft-deletes it');
assertSame('0', (string) $deletedCard['is_favorite'], 'deleting favorite clears favorite status');
assertTrue($deletedCard['deleted_at'] !== null, 'deleting card stamps deleted_at');
expectHttpException(
    fn() => $taxonomy->updateCard(apiKeyRequest('PATCH', '/me/cards/1', $apiKeyUser1, ['is_favorite' => true]), ['card_id' => '1']),
    404,
    'NOT_FOUND',
    'favorite updates reject deleted cards'
);

$suggestionsResponse = $transactions->suggestions(apiKeyRequest('GET', '/me/transactions/suggestions', $apiKeyUser1, query: ['q' => 'coffee']));
$suggestionsPayload = decodeJsonBody($suggestionsResponse->body);
assertSame('3', $suggestionsPayload['items'][0]['card']['id'] ?? null, 'transaction suggestions remain history-based instead of favorite-based');
assertSame(false, $suggestionsPayload['items'][0]['card']['is_favorite'] ?? null, 'transaction suggestion card payload serializes favorite field');

$createTransactionResponse = $transactions->create(apiKeyRequest('POST', '/me/transactions', $apiKeyUser1, [
    'date' => '2026-06-18',
    'expense' => 'Movie Snacks',
    'amount' => '14.25',
    'category' => 'wants',
    'is_split' => false,
    'tag_id' => '1',
    'card_id' => '2',
    'notes' => '  Bought snacks for movie night  ',
]));
$createdTransaction = decodeJsonBody($createTransactionResponse->body);
assertSame('Bought snacks for movie night', $createdTransaction['notes'] ?? null, 'transaction create trims and returns notes');

$updatedTransactionResponse = $transactions->update(
    apiKeyRequest('PATCH', '/me/transactions/' . $createdTransaction['id'], $apiKeyUser1, ['notes' => " \t "]),
    ['transaction_id' => (string) $createdTransaction['id']]
);
$updatedTransaction = decodeJsonBody($updatedTransactionResponse->body);
assertTrue(array_key_exists('notes', $updatedTransaction), 'transaction update keeps notes field in payload');
assertSame(null, $updatedTransaction['notes'], 'transaction update clears blank notes to null');

expectHttpException(
    fn() => $transactions->create(apiKeyRequest('POST', '/me/transactions', $apiKeyUser1, [
        'date' => '2026-06-19',
        'expense' => 'Long Note',
        'amount' => '1.00',
        'category' => 'wants',
        'tag_id' => '1',
        'notes' => str_repeat('é', 256),
    ])),
    422,
    'VALIDATION_ERROR',
    'transaction create rejects overlong utf8 notes'
);
expectHttpException(
    fn() => $transactions->update(
        apiKeyRequest('PATCH', '/me/transactions/' . $createdTransaction['id'], $apiKeyUser1, ['notes' => ['bad']]),
        ['transaction_id' => (string) $createdTransaction['id']]
    ),
    422,
    'VALIDATION_ERROR',
    'transaction update rejects nonstring notes'
);

fwrite(STDOUT, "Card favorites smoke test passed\n");

function apiKeyRequest(string $method, string $path, string $apiKey, array $payload = [], array $query = []): Request
{
    return new Request(
        method: $method,
        path: $path,
        rawBody: $payload === [] ? '' : json_encode($payload, JSON_THROW_ON_ERROR),
        query: $query,
        cookies: [],
        files: [],
        post: [],
        headers: ['X-API-Key' => $apiKey]
    );
}

/** @return array<string,mixed> */
function decodeJsonBody(string $body): array
{
    /** @var array<string,mixed> $decoded */
    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    return $decoded;
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fail(sprintf('%s: expected %s, got %s', $label, formatValue($expected), formatValue($actual)));
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
    } catch (HttpException $exception) {
        assertSame($status, $exception->status, $label . ' status');
        assertSame($code, $exception->errorCode, $label . ' code');
        return;
    }

    fail($label . ': expected HttpException');
}

function formatValue(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'null';
}

function fail(string $message): void
{
    fwrite(STDERR, "Card favorites smoke test failed: {$message}\n");
    exit(1);
}
