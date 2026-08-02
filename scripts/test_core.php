<?php

declare(strict_types=1);

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Support\ContextIconKeys;
use App\Support\Str;

require __DIR__ . '/../src/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$response = Response::json(['ok' => true], 201);
$assert($response->status === 201, 'json response keeps status');
$assert($response->body === '{"ok":true}', 'json response encodes compact JSON');
$assert(($response->headers['Content-Type'] ?? null) === 'application/json', 'json response sets content type');
$assert($response->withHeader('X-Test', 'yes')->headers['X-Test'] === 'yes', 'response headers are immutable');
$assert(Response::raw('csv', 202, ['Content-Type' => 'text/csv'])->status === 202, 'raw response keeps status');

$request = new Request('POST', '/api/v1/widgets/abc', '{"json_value":"from-json"}', ['page' => '1'], [], [], ['json_value' => 'from-post'], ['Content-Type' => 'application/json']);
$assert($request->header('content-type') === 'application/json', 'request headers are case-insensitive');
$assert($request->input('json_value') === 'from-post', 'request input prefers form fields');
$assert($request->json() === ['json_value' => 'from-json'], 'request decodes JSON');

$router = new Router();
$router->add('GET', '/widgets/{widget_id}', static fn(Request $request, array $params): Response => Response::json(['widget_id' => $params['widget_id']]));
$routed = $router->dispatch(new Request('GET', '/widgets/abc-123', '', [], [], [], [], []));
$assert(json_decode($routed->body, true)['widget_id'] === 'abc-123', 'router passes named path parameters');
try {
    $router->dispatch(new Request('POST', '/widgets/abc-123', '', [], [], [], [], []));
    throw new RuntimeException('router accepted an unmatched method');
} catch (HttpException $error) {
    $assert($error->status === 404 && $error->errorCode === 'NOT_FOUND', 'router rejects unmatched methods');
}

$assert((bool) preg_match('/^tst_[a-f0-9]{20}$/', Str::randomId('tst')), 'random IDs retain the expected shape');
$assert(count(ContextIconKeys::all()) === 33, 'context icon vocabulary remains stable');
$assert(!ContextIconKeys::isValid('not-a-context-icon'), 'invalid context icon is rejected');

echo "Backend core tests passed\n";
