<?php

declare(strict_types=1);

namespace App\Auth;

final class AuthContext
{
    /** @param array<string, mixed> $user */
    public function __construct(
        public readonly array $user,
        public readonly string $authType,
        public readonly ?string $sessionId = null,
        public readonly ?string $apiKeyId = null,
        public readonly ?string $sessionSource = null
    ) {
    }

    public function userId(): int
    {
        return (int) $this->user['id'];
    }

    public function role(): string
    {
        return (string) $this->user['role'];
    }
}
