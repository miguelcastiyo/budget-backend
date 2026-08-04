<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Named seam for the authentication response contract.
 *
 * Response shaping remains owned by AuthApplicationService during this
 * compatibility pass; this class provides the stable extraction point for the
 * next mechanical move without changing response fields or cookie behavior.
 */
final class AuthResponseBuilder
{
    public const WEB_CLIENT = 'web';
    public const NATIVE_CLIENT = 'native';
}
