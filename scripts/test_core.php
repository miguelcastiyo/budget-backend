<?php

declare(strict_types=1);

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Controllers\AuthController;
use App\Controllers\ImportExportController;
use App\Controllers\MasterApiKeyController;
use App\Core\App;
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
assertSame('/me/dashboard', $normalizePath->invoke($app, '/api/v1/me/dashboard'), 'rate limiter normalizes api v1 paths');
assertSame('/me/dashboard', $normalizePath->invoke($app, '/me/dashboard'), 'rate limiter keeps direct paths');
assertSame('ses_abc', $sessionIdFromToken->invoke($app, 'ses_abc.secret'), 'rate limiter extracts session id from token');
assertSame(null, $sessionIdFromToken->invoke($app, 'broken-token'), 'rate limiter rejects malformed session token');

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
