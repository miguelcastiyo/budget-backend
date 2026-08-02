<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Http\Request;
use App\Http\Response;
use App\Privacy\FinancialPrivacyStateService;

/** Public account privacy metadata for the supported encrypted account lifecycle. */
final class PrivacyStatusController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly FinancialPrivacyStateService $states
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request);

        return Response::json([
            'financial_privacy_state' => $this->states->get($ctx->userId())->value,
        ]);
    }
}
