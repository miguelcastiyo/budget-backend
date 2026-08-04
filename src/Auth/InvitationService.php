<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\Request;
use App\Http\Response;

final class InvitationService
{
    public function __construct(private readonly AuthApplicationService $application)
    {
    }

    public function create(Request $request): Response { return $this->application->createInvitation($request); }
    public function list(Request $request): Response { return $this->application->listInvitations($request); }
    /** @param array{invite_id:string} $params */
    public function revoke(Request $request, array $params): Response { return $this->application->revokeInvitation($request, $params); }
    /** @param array{invite_id:string} $params */
    public function deleteAccount(Request $request, array $params): Response { return $this->application->deleteInvitedAccount($request, $params); }
    public function preview(Request $request): Response { return $this->application->previewInvitation($request); }
    public function acceptPassword(Request $request): Response { return $this->application->acceptInvitationPassword($request); }
    public function acceptGoogle(Request $request): Response { return $this->application->acceptInvitationGoogle($request); }
}
