<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Http\Request;
use App\Http\Response;
use App\Overview\MonthOverviewService;

final class MonthOverviewController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly MonthOverviewService $service
    ) {
    }

    /** @param array{month:string} $params */
    public function overview(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);

        return Response::json($this->service->getOverviewForMonth($ctx->userId(), (string) ($params['month'] ?? '')));
    }
}
