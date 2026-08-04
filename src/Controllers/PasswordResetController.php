<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\PasswordResetService;
use App\Http\Request;
use App\Http\Response;

final class PasswordResetController
{
    public function __construct(private readonly PasswordResetService $passwordResets)
    {
    }

    public function request(Request $request): Response { return $this->passwordResets->request($request); }
    public function confirm(Request $request): Response { return $this->passwordResets->confirm($request); }
}
