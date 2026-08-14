<?php

declare(strict_types=1);

namespace App\Auth;

use PDO;

/** New-table authority for password credentials. */
final class PasswordCredentialRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,mixed>|false */
    public function findForUser(int $userId): array|false
    {
        $stmt = $this->pdo->prepare('SELECT user_id, password_hash, created_at, last_used_at, password_changed_at FROM password_credentials WHERE user_id = :user_id LIMIT 1');
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetch();
    }

    public function existsForUser(int $userId): bool
    {
        return $this->findForUser($userId) !== false;
    }

    public function create(int $userId, string $hash): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO password_credentials (user_id, password_hash) VALUES (:user_id, :password_hash)');
        $stmt->execute([':user_id' => $userId, ':password_hash' => $hash]);
    }

    public function updateHash(int $userId, string $hash): void
    {
        $stmt = $this->pdo->prepare('UPDATE password_credentials SET password_hash = :password_hash, password_changed_at = UTC_TIMESTAMP() WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId, ':password_hash' => $hash]);
        if ($stmt->rowCount() !== 1) throw new \RuntimeException('Password credential is missing');
    }

    public function deleteForUser(int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM password_credentials WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId]);
        if ($stmt->rowCount() !== 1) throw new \RuntimeException('Password credential is missing');
    }

    public function markUsed(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE password_credentials SET last_used_at = UTC_TIMESTAMP() WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId]);
    }
}
