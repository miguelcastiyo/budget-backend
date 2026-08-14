<?php

declare(strict_types=1);

namespace App\Auth;

use App\Monitoring\StructuredLogger;

/** Reads only Piece 1/2 authoritative auth tables; never legacy users columns. */
final class AuthMethodService
{
    public function __construct(
        private readonly AuthIdentityRepository $identities,
        private readonly PasswordCredentialRepository $passwords,
        private readonly StructuredLogger $logger
    ) {}

    /** @return list<AuthMethod> */
    public function listForUser(int $userId): array
    {
        $methods = [];
        $password = $this->passwords->findForUser($userId);
        if ($password) $methods[] = new AuthMethod('password', null, $this->timestamp($password['created_at'] ?? null), $this->timestamp($password['last_used_at'] ?? null));
        foreach ($this->identities->listForUser($userId) as $identity) {
            $methods[] = new AuthMethod((string) $identity['provider'], $identity['provider_email'] !== null ? (string) $identity['provider_email'] : null, $this->timestamp($identity['created_at'] ?? null), $this->timestamp($identity['last_used_at'] ?? null));
        }
        usort($methods, static fn(AuthMethod $a, AuthMethod $b): int => $a->type <=> $b->type);
        if (count($methods) !== 1) $this->logger->warning('auth_method_invariant_violation', 'Unexpected authoritative auth method count', ['actor_user_id' => $userId, 'action' => count($methods) === 0 ? 'zero_methods' : 'multiple_methods']);
        return $methods;
    }

    public function hasPassword(int $userId): bool { return $this->passwords->existsForUser($userId); }
    public function hasExternalProvider(int $userId, string $provider): bool { return $this->identities->findForUserAndProvider($userId, $provider) !== false; }
    public function countForUser(int $userId): int { return count($this->listForUser($userId)); }

    private function timestamp(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') return null;
        $time = strtotime($value . ' UTC');
        return $time === false ? null : gmdate('c', $time);
    }
}
