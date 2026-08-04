<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\Request;
use App\Http\Response;

final class PasswordResetService
{
    public function __construct(private readonly AuthApplicationService $application)
    {
    }

    public function request(Request $request): Response { return $this->application->requestPasswordReset($request); }
    public function confirm(Request $request): Response { return $this->application->confirmPasswordReset($request); }
}
