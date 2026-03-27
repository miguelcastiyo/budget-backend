<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;

final class HealthController
{
    public function __invoke(Request $request): Response
    {
        return Response::json([
            'ok' => true,
            'service' => 'budget-api',
            'time' => gmdate('c'),
        ]);
    }
}
