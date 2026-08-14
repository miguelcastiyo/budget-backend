<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\HttpException;
use App\Monitoring\StructuredLogger;
use PDO;

/** Reads only Piece 1/2 authoritative auth tables; never legacy users columns. */
final class AuthMethodService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthIdentityRepository $identities,
        private readonly PasswordCredentialRepository $passwords,
        private readonly StructuredLogger $logger
    ) {}

    /** @return list<AuthMethod> */
    public function listForUser(int $userId): array
    {
        $methods = [];
        $password = $this->passwords->findForUser($userId);
        $methodCount = $this->countForUser($userId);
        if ($password) $methods[] = new AuthMethod('password', null, $this->timestamp($password['created_at'] ?? null), $this->timestamp($password['last_used_at'] ?? null), $methodCount > 1);
        foreach ($this->identities->listForUser($userId) as $identity) {
            $methods[] = new AuthMethod((string) $identity['provider'], $identity['provider_email'] !== null ? (string) $identity['provider_email'] : null, $this->timestamp($identity['created_at'] ?? null), $this->timestamp($identity['last_used_at'] ?? null), $methodCount > 1);
        }
        usort($methods, static fn(AuthMethod $a, AuthMethod $b): int => $a->type <=> $b->type);
        if ($methodCount === 0) $this->logger->warning('auth_method_invariant_violation', 'Account has no authoritative auth methods', ['actor_user_id' => $userId, 'action' => 'zero_methods']);
        return $methods;
    }

    public function hasPassword(int $userId): bool { return $this->passwords->existsForUser($userId); }
    public function hasExternalProvider(int $userId, string $provider): bool { return $this->identities->findForUserAndProvider($userId, $provider) !== false; }
    public function countForUser(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT (SELECT COUNT(*) FROM auth_identities WHERE user_id = :identity_user_id) + (SELECT COUNT(*) FROM password_credentials WHERE user_id = :password_user_id)');
        $stmt->execute([':identity_user_id' => $userId, ':password_user_id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function connectGoogle(int $userId, string $subject, string $email, bool $emailVerified): void
    {
        $this->mutate($userId, function () use ($userId, $subject, $email, $emailVerified): void {
            $current = $this->identities->findForUserAndProvider($userId, 'google');
            if ($current) {
                if ((string) $current['provider_subject'] === $subject) return;
                throw new HttpException(409, 'GOOGLE_METHOD_ALREADY_CONNECTED', 'A different Google account is already connected.');
            }
            $existing = $this->identities->findByProviderSubject('google', $subject);
            if ($existing && (int) $existing['user_id'] !== $userId) throw new HttpException(409, 'CONFLICT', 'This Google account is already connected to another Budget account.');
            $this->identities->createGoogle($userId, $subject, $email, $emailVerified);
        });
    }

    public function addPassword(int $userId, string $hash): void
    {
        $this->mutate($userId, function () use ($userId, $hash): void {
            if ($this->passwords->existsForUser($userId)) throw new HttpException(409, 'PASSWORD_METHOD_ALREADY_CONNECTED', 'A password sign-in method is already connected.');
            $this->passwords->create($userId, $hash);
        });
    }

    public function changePassword(int $userId, string $hash): void
    {
        $this->mutate($userId, function () use ($userId, $hash): void {
            if (!$this->passwords->existsForUser($userId)) throw new HttpException(404, 'NOT_FOUND', 'Password sign-in method not found.');
            $this->passwords->updateHash($userId, $hash);
        });
    }

    public function removePassword(int $userId): void { $this->remove($userId, 'password'); }
    public function removeExternalProvider(int $userId, string $provider): void { $this->remove($userId, $provider); }

    private function remove(int $userId, string $method): void
    {
        $this->mutate($userId, function () use ($userId, $method): void {
            $exists = $method === 'password' ? $this->passwords->existsForUser($userId) : $this->identities->findForUserAndProvider($userId, $method) !== false;
            if (!$exists) throw new HttpException(404, 'NOT_FOUND', 'Sign-in method not found.');
            if ($this->countForUser($userId) <= 1) throw new HttpException(409, 'LAST_AUTH_METHOD', 'Add another sign-in method before removing this one.');
            if ($method === 'password') $this->passwords->deleteForUser($userId); else $this->identities->deleteForUserAndProvider($userId, $method);
            if ($this->countForUser($userId) < 1) throw new \RuntimeException('Auth method mutation would leave account without a method');
        });
    }

    private function mutate(int $userId, callable $operation): void
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? 'SELECT id FROM users WHERE id = :user_id'
                : 'SELECT id FROM users WHERE id = :user_id FOR UPDATE';
            $stmt = $this->pdo->prepare($lock);
            $stmt->execute([':user_id' => $userId]);
            if (!$stmt->fetch()) throw new HttpException(404, 'NOT_FOUND', 'User not found.');
            $operation();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function timestamp(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') return null;
        $time = strtotime($value . ' UTC');
        return $time === false ? null : gmdate('c', $time);
    }
}
