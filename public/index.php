<?php

declare(strict_types=1);

use App\Core\App;
use App\Http\Request;

require __DIR__ . '/../src/bootstrap.php';

$app = App::create();
$request = Request::capture();
$response = $app->handle($request);
$response->send();
