<?php

declare(strict_types=1);

namespace App\Privacy;

use PDO;

final class VaultRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByUser(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT vault_id, user_id, crypto_profile_version, passphrase_kdf, passphrase_kdf_hash, passphrase_kdf_iterations, passphrase_wrap_algorithm, passphrase_kdf_salt, passphrase_wrapped_vault_key, recovery_wrap_algorithm, recovery_wrapped_vault_key, created_at, updated_at FROM user_financial_vaults WHERE user_id = :user_id LIMIT 1');
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $data */
    public function create(int $userId, string $vaultId, array $data): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_financial_vaults (vault_id, user_id, crypto_profile_version, passphrase_kdf, passphrase_kdf_hash, passphrase_kdf_iterations, passphrase_wrap_algorithm, passphrase_kdf_salt, passphrase_wrapped_vault_key, recovery_wrap_algorithm, recovery_wrapped_vault_key) VALUES (:vault_id, :user_id, :profile_version, :kdf, :kdf_hash, :iterations, :passphrase_wrap, :salt, :passphrase_wrapped, :recovery_wrap, :recovery_wrapped)');
        $stmt->execute([
            ':vault_id' => $vaultId,
            ':user_id' => $userId,
            ':profile_version' => $data['crypto_profile_version'],
            ':kdf' => $data['passphrase_kdf'],
            ':kdf_hash' => $data['passphrase_kdf_hash'],
            ':iterations' => $data['passphrase_kdf_iterations'],
            ':passphrase_wrap' => $data['passphrase_wrap_algorithm'],
            ':salt' => $data['passphrase_kdf_salt'],
            ':passphrase_wrapped' => $data['passphrase_wrapped_vault_key'],
            ':recovery_wrap' => $data['recovery_wrap_algorithm'],
            ':recovery_wrapped' => $data['recovery_wrapped_vault_key'],
        ]);

        return $this->findByUser($userId) ?? throw new \RuntimeException('Vault was not persisted');
    }

    public function replacePassphraseWrapper(int $userId, array $data): array
    {
        $stmt = $this->pdo->prepare('UPDATE user_financial_vaults SET passphrase_kdf = :kdf, passphrase_kdf_hash = :kdf_hash, passphrase_kdf_iterations = :iterations, passphrase_wrap_algorithm = :wrap_algorithm, passphrase_kdf_salt = :salt, passphrase_wrapped_vault_key = :wrapped WHERE user_id = :user_id');
        $stmt->execute([
            ':kdf' => $data['passphrase_kdf'], ':kdf_hash' => $data['passphrase_kdf_hash'],
            ':iterations' => $data['passphrase_kdf_iterations'], ':wrap_algorithm' => $data['passphrase_wrap_algorithm'],
            ':salt' => $data['passphrase_kdf_salt'], ':wrapped' => $data['passphrase_wrapped_vault_key'], ':user_id' => $userId,
        ]);
        return $this->findByUser($userId) ?? throw new \RuntimeException('Vault was not persisted');
    }

    public function replaceRecoveryWrapper(int $userId, string $wrapped): array
    {
        $stmt = $this->pdo->prepare('UPDATE user_financial_vaults SET recovery_wrapped_vault_key = :wrapped WHERE user_id = :user_id');
        $stmt->execute([':wrapped' => $wrapped, ':user_id' => $userId]);
        return $this->findByUser($userId) ?? throw new \RuntimeException('Vault was not persisted');
    }
}
