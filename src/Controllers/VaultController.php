<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Http\Request;
use App\Http\Response;
use App\Privacy\VaultService;

final class VaultController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly VaultService $vaults
    ) {
    }

    public function get(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: false, sessionOnly: true);
        return Response::json($this->vaults->metadata($ctx->userId()));
    }

    public function initialize(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: false, sessionOnly: true);
        return Response::json($this->vaults->initialize($ctx, $request->json(), $request), 201);
    }

    public function replacePassphrase(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: false, sessionOnly: true);
        return Response::json($this->vaults->replacePassphrase($ctx, $request->json(), $request));
    }

    public function replaceRecovery(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: false, sessionOnly: true);
        return Response::json($this->vaults->replaceRecovery($ctx, $request->json(), $request));
    }
}
