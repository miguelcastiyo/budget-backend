<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Config;
use App\Controllers\HealthController;
use App\Database\Connection;
use App\Http\Request;
use App\Monitoring\StructuredLogger;

require __DIR__ . '/../src/bootstrap.php';

$request = Request::capture();

if ($request->method === 'GET' && in_array($request->path, ['/health', '/api/v1/health'], true)) {
    $response = (new HealthController())->liveness($request);
    $response->send();
    return;
}

if ($request->method === 'GET' && in_array($request->path, ['/ready', '/api/v1/ready'], true)) {
    $config = Config::load(dirname(__DIR__));
    $response = (new HealthController(new StructuredLogger($config)))->readiness($request, static function () use ($config): void {
        $pdo = Connection::make($config);
        if ($pdo->query('SELECT 1') === false) {
            throw new RuntimeException('Database readiness query failed');
        }
    });
    $response->send();
    return;
}

$app = App::create();
$response = $app->handle($request);
$response->send();
