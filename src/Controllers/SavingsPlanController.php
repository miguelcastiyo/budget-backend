<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Http\Request;
use App\Http\Response;
use App\Savings\SavingsPlanService;

final class SavingsPlanController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly SavingsPlanService $service
    ) {
    }

    /** @param array{month:string} $params */
    public function get(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        return Response::json($this->service->getForMonth($ctx->userId(), (string) ($params['month'] ?? '')));
    }

    /** @param array{month:string} $params */
    public function replace(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        return Response::json($this->service->replaceForMonth($ctx->userId(), (string) ($params['month'] ?? ''), $request->json()));
    }
}
