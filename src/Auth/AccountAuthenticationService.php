<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\Request;
use App\Http\Response;

final class AccountAuthenticationService
{
    public function __construct(private readonly AuthApplicationService $application)
    {
    }

    public function passwordSignIn(Request $request): Response { return $this->application->signInPassword($request); }
    public function googleSignIn(Request $request): Response { return $this->application->signInGoogle($request); }
    public function acceptPasswordInvitation(Request $request): Response { return $this->application->acceptInvitationPassword($request); }
    public function acceptGoogleInvitation(Request $request): Response { return $this->application->acceptInvitationGoogle($request); }
}
