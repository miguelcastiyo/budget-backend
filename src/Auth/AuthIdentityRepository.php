<?php

declare(strict_types=1);

namespace App\Auth;

use PDO;

/** New-table authority for external identities. */
final class AuthIdentityRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,mixed>|false */
    public function findByProviderSubject(string $provider, string $subject): array|false
    {
        $stmt = $this->pdo->prepare('SELECT id, user_id, provider, provider_subject, provider_email, provider_email_verified FROM auth_identities WHERE provider = :provider AND BINARY provider_subject = BINARY :subject LIMIT 1');
        $stmt->execute([':provider' => $provider, ':subject' => $subject]);
        return $stmt->fetch();
    }

    /** @return array<string,mixed>|false */
    public function findForUserAndProvider(int $userId, string $provider): array|false
    {
        $stmt = $this->pdo->prepare('SELECT id, user_id, provider, provider_subject, provider_email, provider_email_verified FROM auth_identities WHERE user_id = :user_id AND provider = :provider LIMIT 1');
        $stmt->execute([':user_id' => $userId, ':provider' => $provider]);
        return $stmt->fetch();
    }

    /** @return list<array<string,mixed>> */
    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT provider, provider_email, created_at, last_used_at FROM auth_identities WHERE user_id = :user_id ORDER BY created_at ASC, id ASC');
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function createGoogle(int $userId, string $subject, string $email, bool $emailVerified): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO auth_identities (user_id, provider, provider_subject, provider_email, provider_email_verified) VALUES (:user_id, 'google', :subject, :email, :email_verified)");
        $stmt->execute([':user_id' => $userId, ':subject' => $subject, ':email' => $email, ':email_verified' => $emailVerified ? 1 : 0]);
    }

    public function markGoogleUsed(int $userId, string $email, bool $emailVerified): void
    {
        $stmt = $this->pdo->prepare("UPDATE auth_identities SET last_used_at = UTC_TIMESTAMP(), provider_email = :email, provider_email_verified = :email_verified WHERE user_id = :user_id AND provider = 'google'");
        $stmt->execute([':user_id' => $userId, ':email' => $email, ':email_verified' => $emailVerified ? 1 : 0]);
    }

    public function deleteForUserAndProvider(int $userId, string $provider): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM auth_identities WHERE user_id = :user_id AND provider = :provider');
        $stmt->execute([':user_id' => $userId, ':provider' => $provider]);
        if ($stmt->rowCount() !== 1) throw new \RuntimeException('Auth identity is missing');
    }
}
