<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AccountAuthenticationService;
use App\Auth\SessionService;
use App\Http\Request;
use App\Http\Response;

final class SessionController
{
    public function __construct(
        private readonly AccountAuthenticationService $accounts,
        private readonly SessionService $sessions
    )
    {
    }

    public function passwordSignIn(Request $request): Response { return $this->accounts->passwordSignIn($request); }
    public function googleSignIn(Request $request): Response { return $this->accounts->googleSignIn($request); }
    public function refreshCsrf(Request $request): Response { return $this->sessions->refreshCsrf($request); }
    public function signOut(Request $request): Response { return $this->sessions->signOut($request); }
}
