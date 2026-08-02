<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Http\Request;
use App\Http\Response;
use App\Privacy\FinancialPrivacyStateService;
use App\Privacy\FinancialRevisionService;

/** Public account privacy metadata; migration operations remain operator-only. */
final class PrivacyStatusController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly FinancialPrivacyStateService $states,
        private readonly FinancialRevisionService $revisions
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request);

        return Response::json([
            'financial_privacy_state' => $this->states->get($ctx->userId())->value,
            'financial_revision' => $this->revisions->get($ctx->userId()),
        ]);
    }
}
