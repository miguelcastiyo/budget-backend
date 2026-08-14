<?php

declare(strict_types=1);

namespace App\Auth;

/** Provider-neutral, user-facing authentication method inventory item. */
final readonly class AuthMethod
{
    public function __construct(
        public string $type,
        public ?string $providerEmail,
        public ?string $connectedAt,
        public ?string $lastUsedAt
    ) {}

    /** @return array{type:string,provider_email:?string,connected_at:?string,last_used_at:?string} */
    public function toApi(): array
    {
        return ['type' => $this->type, 'provider_email' => $this->providerEmail, 'connected_at' => $this->connectedAt, 'last_used_at' => $this->lastUsedAt];
    }
}
