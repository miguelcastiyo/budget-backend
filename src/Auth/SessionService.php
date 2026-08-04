<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\Request;
use App\Http\Response;

final class SessionService
{
    public function __construct(private readonly AuthApplicationService $application)
    {
    }

    public function refreshCsrf(Request $request): Response { return $this->application->refreshCurrentSessionCsrf($request); }
    public function signOut(Request $request): Response { return $this->application->signOutCurrentSession($request); }
}
