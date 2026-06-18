<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Http\Request;
use App\Http\Response;
use App\MonthCloseout\MonthCloseoutService;

final class MonthCloseoutController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly MonthCloseoutService $service
    ) {
    }

    public function list(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);

        return Response::json($this->service->listMonthCloseouts($ctx->userId(), $request->query));
    }

    /** @param array{month:string} $params */
    public function get(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);

        return Response::json($this->service->getMonthCloseout($ctx->userId(), (string) ($params['month'] ?? '')));
    }

    /** @param array{month:string} $params */
    public function close(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);

        return Response::json($this->service->closeMonth($ctx->userId(), (string) ($params['month'] ?? ''), $request->json()));
    }

    /** @param array{month:string} $params */
    public function update(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);

        return Response::json($this->service->updateCloseout($ctx->userId(), (string) ($params['month'] ?? ''), $request->json()));
    }

    /** @param array{month:string} $params */
    public function reopen(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);

        return Response::json($this->service->reopenMonth($ctx->userId(), (string) ($params['month'] ?? '')));
    }
}
