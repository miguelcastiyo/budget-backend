<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AccountAuthenticationService;
use App\Auth\InvitationService;
use App\Http\Request;
use App\Http\Response;

final class InvitationController
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly AccountAuthenticationService $accounts
    )
    {
    }

    public function create(Request $request): Response { return $this->invitations->create($request); }
    public function list(Request $request): Response { return $this->invitations->list($request); }
    /** @param array{invite_id:string} $params */
    public function revoke(Request $request, array $params): Response { return $this->invitations->revoke($request, $params); }
    /** @param array{invite_id:string} $params */
    public function deleteAccount(Request $request, array $params): Response { return $this->invitations->deleteAccount($request, $params); }
    public function preview(Request $request): Response { return $this->invitations->preview($request); }
    public function acceptPassword(Request $request): Response { return $this->accounts->acceptPasswordInvitation($request); }
    public function acceptGoogle(Request $request): Response { return $this->accounts->acceptGoogleInvitation($request); }
}
