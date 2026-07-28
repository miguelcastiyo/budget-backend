<?php

declare(strict_types=1);

namespace App\Privacy;

final class VaultCryptoProfile
{
    public const VERSION = 1;
    public const VAULT_KEY_ALGORITHM = 'AES-GCM';
    public const VAULT_KEY_LENGTH = 256;
    public const PASSPHRASE_KDF = 'PBKDF2';
    public const KDF_HASH = 'SHA-256';
    public const KDF_ITERATIONS = 600000;
    public const SALT_BYTES = 32;
    public const WRAP_ALGORITHM = 'AES-KW';
    public const RECOVERY_SECRET_BYTES = 32;

    /** @return array<string, int|string> */
    public static function metadata(): array
    {
        return [
            'crypto_profile_version' => self::VERSION,
            'vault_key_algorithm' => self::VAULT_KEY_ALGORITHM,
            'vault_key_length' => self::VAULT_KEY_LENGTH,
            'passphrase_kdf' => self::PASSPHRASE_KDF,
            'passphrase_kdf_hash' => self::KDF_HASH,
            'passphrase_kdf_iterations' => self::KDF_ITERATIONS,
            'passphrase_salt_length' => self::SALT_BYTES,
            'passphrase_wrap_algorithm' => self::WRAP_ALGORITHM,
            'recovery_wrap_algorithm' => self::WRAP_ALGORITHM,
        ];
    }
}
