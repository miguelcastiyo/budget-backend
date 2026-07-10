<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Funds\FundService;
use App\Http\Request;
use App\Http\Response;

final class FundController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly FundService $service
    ) {
    }

    public function list(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        return Response::json($this->service->listFunds($ctx->userId(), $request->query));
    }

    public function create(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        return Response::json($this->service->createFund($ctx->userId(), $request->json()), 201);
    }

    /** @param array{fund_id:string} $params */
    public function get(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        return Response::json($this->service->getFund($ctx->userId(), (string) ($params['fund_id'] ?? '')));
    }

    /** @param array{fund_id:string} $params */
    public function update(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        return Response::json($this->service->updateFund($ctx->userId(), (string) ($params['fund_id'] ?? ''), $request->json()));
    }

    /** @param array{fund_id:string} $params */
    public function archive(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        return Response::json($this->service->archiveFund($ctx->userId(), (string) ($params['fund_id'] ?? '')));
    }

    /** @param array{fund_id:string} $params */
    public function restore(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        return Response::json($this->service->restoreFund($ctx->userId(), (string) ($params['fund_id'] ?? '')));
    }

    /** @param array{fund_id:string} $params */
    public function entries(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        return Response::json($this->service->listEntries($ctx->userId(), (string) ($params['fund_id'] ?? ''), $request->query));
    }

    /** @param array{fund_id:string} $params */
    public function createEntry(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        return Response::json($this->service->createEntry($ctx->userId(), (string) ($params['fund_id'] ?? ''), $request->json()), 201);
    }

    /** @param array{fund_id:string,entry_id:string} $params */
    public function updateEntry(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        return Response::json($this->service->updateEntry(
            $ctx->userId(),
            (string) ($params['fund_id'] ?? ''),
            (string) ($params['entry_id'] ?? ''),
            $request->json()
        ));
    }

    /** @param array{fund_id:string,entry_id:string} $params */
    public function deleteEntry(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $this->service->deleteEntry($ctx->userId(), (string) ($params['fund_id'] ?? ''), (string) ($params['entry_id'] ?? ''));
        return Response::noContent();
    }

    public function closeoutSummary(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $year = (int) ($request->query['year'] ?? gmdate('Y'));
        return Response::json($this->service->closeoutSummary($ctx->userId(), $year));
    }
}
