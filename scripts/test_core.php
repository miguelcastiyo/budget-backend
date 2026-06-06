<?php

declare(strict_types=1);

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Controllers\AuthController;
use App\Controllers\ImportExportController;
use App\Controllers\MasterApiKeyController;
use App\Controllers\TaxonomyController;
use App\Controllers\TransactionController;
use App\Core\App;
use App\Core\Config;
use App\Monitoring\StructuredLogger;
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

$importExportReflection = new ReflectionClass(ImportExportController::class);
$importExportController = $importExportReflection->newInstanceWithoutConstructor();
$csvCell = $importExportReflection->getMethod('csvCell');
assertSame("'=SUM(A1:A2)", $csvCell->invoke($importExportController, '=SUM(A1:A2)'), 'csv export escapes equals formulas');
assertSame("'+cmd", $csvCell->invoke($importExportController, '+cmd'), 'csv export escapes plus formulas');
assertSame("'-cmd", $csvCell->invoke($importExportController, '-cmd'), 'csv export escapes minus formulas');
assertSame("'@cmd", $csvCell->invoke($importExportController, '@cmd'), 'csv export escapes at formulas');
assertSame("' =cmd", $csvCell->invoke($importExportController, ' =cmd'), 'csv export escapes formulas after leading whitespace');
assertSame('Groceries', $csvCell->invoke($importExportController, 'Groceries'), 'csv export leaves normal cells unchanged');
$clampedDataRunsLimit = $importExportReflection->getMethod('clampedDataRunsLimit');
assertSame(50, $clampedDataRunsLimit->invoke($importExportController, null), 'data runs limit defaults to 50');
assertSame(1, $clampedDataRunsLimit->invoke($importExportController, '0'), 'data runs limit clamps minimum');
assertSame(100, $clampedDataRunsLimit->invoke($importExportController, '500'), 'data runs limit clamps maximum');
assertSame(25, $clampedDataRunsLimit->invoke($importExportController, '25'), 'data runs limit accepts in-range values');
$dataRunItem = $importExportReflection->getMethod('dataRunItem');
$importRunItem = $dataRunItem->invoke($importExportController, [
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
$oldImportRunItem = $dataRunItem->invoke($importExportController, [
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
$rolledBackImportRunItem = $dataRunItem->invoke($importExportController, [
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
$exportRunItem = $dataRunItem->invoke($importExportController, [
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
$suggestImportMapping = $importExportReflection->getMethod('suggestImportMapping');
$suggestedMapping = $suggestImportMapping->invoke($importExportController, ['Posted Date', 'Description', 'Amount', 'Budget Category', 'Tag', 'Account']);
assertSame('Posted Date', $suggestedMapping['date'] ?? null, 'csv import preview suggests date mapping from alias');
assertSame('Description', $suggestedMapping['expense'] ?? null, 'csv import preview suggests expense mapping from alias');
assertSame('Account', $suggestedMapping['card'] ?? null, 'csv import preview suggests card mapping from alias');
$bankSuggestedMapping = $suggestImportMapping->invoke($importExportController, ['Transaction Date', 'Vendor / Payee', 'Money Out', 'Money In', 'Bank Category Guess', 'Payment Source']);
assertSame('Vendor / Payee', $bankSuggestedMapping['expense'] ?? null, 'csv import preview suggests bank vendor/payee mapping');
assertSame('Money Out', $bankSuggestedMapping['amount'] ?? null, 'csv import preview suggests bank money out mapping');
assertSame('Bank Category Guess', $bankSuggestedMapping['tag'] ?? null, 'csv import preview suggests bank category guess tag mapping');
assertSame('Payment Source', $bankSuggestedMapping['card'] ?? null, 'csv import preview suggests bank payment source card mapping');
$validatedImportMapping = $importExportReflection->getMethod('validatedImportMapping');
$mapping = $validatedImportMapping->invoke($importExportController, json_encode([
    'date' => 'Posted Date',
    'expense' => 'Description',
    'amount' => 'Amount',
    'category' => 'Budget Category',
    'tag' => 'Tag',
    'card' => 'Account',
], JSON_THROW_ON_ERROR), ['Posted Date', 'Description', 'Amount', 'Budget Category', 'Tag', 'Account']);
assertSame(0, $mapping['date'], 'csv import mapping resolves date index');
assertSame(5, $mapping['card'], 'csv import mapping resolves optional card index');
$valueMapMapping = $validatedImportMapping->invoke($importExportController, json_encode([
    'date' => 'Posted Date',
    'expense' => 'Description',
    'amount' => 'Amount',
    'tag' => 'Bank Category Guess',
], JSON_THROW_ON_ERROR), ['Posted Date', 'Description', 'Amount', 'Bank Category Guess'], ['mode' => 'value_map']);
assertSame(3, $valueMapMapping['tag'], 'csv import mapping allows category to be omitted for value map strategy');
expectHttpException(
    fn() => $validatedImportMapping->invoke($importExportController, '{"date":"Posted Date","expense":"Posted Date","amount":"Amount","category":"Budget Category","tag":"Tag"}', ['Posted Date', 'Amount', 'Budget Category', 'Tag']),
    422,
    'VALIDATION_ERROR',
    'csv import mapping rejects reused headers'
);
expectHttpException(
    fn() => $validatedImportMapping->invoke($importExportController, '{"date":"Missing","expense":"Description","amount":"Amount","category":"Budget Category","tag":"Tag"}', ['Description', 'Amount', 'Budget Category', 'Tag']),
    422,
    'VALIDATION_ERROR',
    'csv import mapping rejects missing source headers'
);
$parseImportRow = $importExportReflection->getMethod('parseImportRow');
$dateStrategyReject = ['missing_year' => 'reject'];
$dateStrategyApplyYear = ['missing_year' => 'apply_year', 'year' => 2026];
$tagStrategy = ['mode' => 'value_map', 'value_map' => [
    'Coffee' => ['mode' => 'new', 'tag_id' => null, 'tag_name' => 'Coffee'],
    'Utilities' => ['mode' => 'new', 'tag_id' => null, 'tag_name' => 'Utilities'],
    'Refund' => ['mode' => 'new', 'tag_id' => null, 'tag_name' => 'Refund'],
]];
$parsedImportRow = $parseImportRow->invoke($importExportController, ['6/1/2026', 'Coffee Shop', '$6.25', 'Wants', 'Coffee', 'Amex Gold', 'yes'], [
    'date' => 0,
    'expense' => 1,
    'amount' => 2,
    'category' => 3,
    'tag' => 4,
    'card' => 5,
    'is_split' => 6,
], ['mode' => 'exact_column'], ['blank_mapped_amount' => 'error'], $dateStrategyReject, $tagStrategy, 2);
assertSame('2026-06-01', $parsedImportRow['date'], 'csv import row normalizes mapped date');
assertSame('6.25', $parsedImportRow['amount'], 'csv import row normalizes mapped amount');
assertSame('wants', $parsedImportRow['category'], 'csv import row normalizes mapped category');
assertSame(true, $parsedImportRow['is_split'], 'csv import row normalizes mapped split flag');
$yearlessImportRow = $parseImportRow->invoke($importExportController, ['3/12', 'Coffee Shop', '$6.25', 'Wants', 'Coffee'], [
    'date' => 0,
    'expense' => 1,
    'amount' => 2,
    'category' => 3,
    'tag' => 4,
], ['mode' => 'exact_column'], ['blank_mapped_amount' => 'error'], $dateStrategyApplyYear, $tagStrategy, 2);
assertSame('2026-03-12', $yearlessImportRow['date'], 'csv import row applies selected year to yearless dates');
expectHttpException(
    fn() => $parseImportRow->invoke($importExportController, ['3/12', 'Coffee Shop', '$6.25', 'Wants', 'Coffee'], [
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
$valueMapImportRow = $parseImportRow->invoke($importExportController, ['6/1/2026', 'LADWP', '49.61', 'Utilities'], [
    'date' => 0,
    'expense' => 1,
    'amount' => 2,
    'tag' => 3,
], ['mode' => 'value_map', 'source_index' => 3, 'value_map' => ['Utilities' => 'needs']], ['blank_mapped_amount' => 'error'], $dateStrategyReject, $tagStrategy, 2);
assertSame('needs', $valueMapImportRow['category'], 'csv import row resolves category from value map strategy');
assertSame('Utilities', $valueMapImportRow['tag_name'], 'csv import row allows the category source to also feed tag');
$blankAmountImportRow = $parseImportRow->invoke($importExportController, ['6/1/2026', 'Refund', '', 'Refund'], [
    'date' => 0,
    'expense' => 1,
    'amount' => 2,
    'tag' => 3,
], ['mode' => 'default', 'default_category' => 'needs'], ['blank_mapped_amount' => 'skip'], $dateStrategyReject, $tagStrategy, 2);
assertSame(null, $blankAmountImportRow, 'csv import row skips blank mapped amount rows when configured');
$inferredTagIconKey = $importExportReflection->getMethod('inferredTagIconKey');
assertSame('coffee', $inferredTagIconKey->invoke($importExportController, 'Coffee Shops'), 'csv import infers coffee icon');
assertSame('tag', $inferredTagIconKey->invoke($importExportController, 'Miscellaneous'), 'csv import falls back to tag icon');
$readImportCsv = $importExportReflection->getMethod('readImportCsv');
$csvHandle = fopen('php://temp', 'r+');
assert($csvHandle !== false);
fwrite($csvHandle, "Posted Date,Description,Amount,Bank Category Guess\n2026-06-01,Coffee,6.25,Coffee\n2026-06-02,Refund,,Refund\n");
rewind($csvHandle);
$csvPreview = $readImportCsv->invoke($importExportController, $csvHandle, 10, 100, true);
fclose($csvHandle);
assertSame(['Posted Date', 'Description', 'Amount', 'Bank Category Guess'], $csvPreview['header'], 'csv import preview reads headers');
assertSame(2, $csvPreview['total_rows'], 'csv import preview counts data rows');
assertSame('Coffee', $csvPreview['sample_rows'][0]['Description'] ?? null, 'csv import preview returns sample rows by header');
assertSame(1, $csvPreview['column_profiles'][2]['blank_count'], 'csv import preview profiles blank counts');
assertSame('Coffee', $csvPreview['column_profiles'][3]['unique_values'][0]['value'] ?? null, 'csv import preview profiles unique source values');

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
