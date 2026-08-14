<?php

declare(strict_types=1);

namespace App\Auth;

use App\Monitoring\StructuredLogger;
use PDO;

/**
 * Temporary Piece 2 bridge. Delete when legacy users auth columns are retired.
 * It only repairs missing new rows; it never resolves conflicting state.
 */
final class LegacyAuthCompatibilityService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthIdentityRepository $identities,
        private readonly PasswordCredentialRepository $passwords,
        private readonly StructuredLogger $logger
    ) {}

    /** @return array<string,mixed>|false */
    public function repairMissingGoogleIdentity(string $subject): array|false
    {
        $legacy = $this->pdo->prepare("SELECT id, email, email_verified FROM users WHERE auth_provider = 'google' AND BINARY google_sub = BINARY :subject AND password_hash IS NULL AND is_active = 1 LIMIT 1");
        $legacy->execute([':subject' => $subject]);
        $user = $legacy->fetch();
        if (!$user) return false;
        if ($this->identities->findForUserAndProvider((int) $user['id'], 'google')) {
            $this->drift('google', 'identity_conflict', (int) $user['id']);
            return false;
        }
        try {
            $this->identities->createGoogle((int) $user['id'], $subject, (string) $user['email'], (bool) $user['email_verified']);
        } catch (\Throwable) {
            $this->drift('google', 'identity_conflict', (int) $user['id']);
            return false;
        }
        $this->logger->warning('auth_legacy_fallback_used', 'Legacy Google identity repaired', ['actor_user_id' => (int) $user['id'], 'action' => 'google_missing_new_identity']);
        return $user;
    }

    /** @param array<string,mixed> $user */
    public function repairMissingPasswordCredential(array $user): bool
    {
        if ((string) ($user['auth_provider'] ?? '') !== 'password' || !is_string($user['password_hash'] ?? null) || $user['password_hash'] === '') {
            $this->drift('password', 'invalid_legacy_state', (int) $user['id']);
            return false;
        }
        try {
            $this->passwords->create((int) $user['id'], (string) $user['password_hash']);
        } catch (\Throwable) {
            $this->drift('password', 'credential_conflict', (int) $user['id']);
            return false;
        }
        $this->logger->warning('auth_legacy_fallback_used', 'Legacy password credential repaired', ['actor_user_id' => (int) $user['id'], 'action' => 'password_missing_new_credential']);
        return true;
    }

    /** @param array<string,mixed> $user */
    public function googleMirrorMatches(array $user, array $identity): bool
    {
        $matches = (string) ($user['auth_provider'] ?? '') === 'google'
            && ($user['password_hash'] ?? null) === null
            && is_string($user['google_sub'] ?? null)
            && hash_equals((string) $user['google_sub'], (string) $identity['provider_subject']);
        if (!$matches) $this->drift('google', 'subject_mismatch', (int) $user['id']);
        return $matches;
    }

    /** @param array<string,mixed> $user */
    public function passwordMirrorMatches(array $user, array $credential): bool
    {
        $matches = (string) ($user['auth_provider'] ?? '') === 'password'
            && ($user['google_sub'] ?? null) === null
            && is_string($user['password_hash'] ?? null)
            && hash_equals((string) $user['password_hash'], (string) $credential['password_hash']);
        if (!$matches) $this->drift('password', 'hash_mismatch', (int) $user['id']);
        return $matches;
    }

    public function drift(string $method, string $reason, int $userId): void
    {
        $this->logger->warning('auth_identity_state_drift', 'Authentication representation drift detected', ['actor_user_id' => $userId, 'action' => $method . '_' . $reason]);
    }
}
