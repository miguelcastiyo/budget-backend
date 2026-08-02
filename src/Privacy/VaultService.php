<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Auth\AuthContext;
use App\Http\HttpException;
use App\Security\AuditLogger;
use App\Support\Str;
use PDO;
use PDOException;

final class VaultService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly VaultRepository $vaults,
        private readonly FinancialPrivacyStateService $states,
        private readonly RecentAuthGuard $recentAuth,
        private readonly AuditLogger $audit
    ) {
    }

    /** @return array<string,mixed> */
    public function metadata(int $userId): array
    {
        $vault = $this->vaults->findByUser($userId);
        if ($vault === null) {
            throw new HttpException(404, 'VAULT_NOT_INITIALIZED', 'Vault is not initialized');
        }

        return $this->response($vault);
    }

    /** @param array<string,mixed> $payload */
    public function initialize(AuthContext $auth, array $payload, ?\App\Http\Request $request = null): array
    {
        $this->recentAuth->requireRecentInteractiveSession($auth);
        $userId = $auth->userId();
        $state = $this->states->get($userId);
        if ($state !== FinancialPrivacyState::VAULT_SETUP_REQUIRED) {
            throw new HttpException(409, 'PRIVACY_STATE_CONFLICT', 'Vault initialization is unavailable in the current financial privacy state');
        }
        $data = $this->validatePayload($payload);
        $existing = $this->vaults->findByUser($userId);
        if ($existing !== null) {
            if ($this->matches($existing, $data)) {
                if ($state === FinancialPrivacyState::VAULT_SETUP_REQUIRED) {
                    $this->pdo->beginTransaction();
                    try {
                        $this->pdo->prepare('INSERT IGNORE INTO encrypted_record_sync_state (user_id, next_sync_sequence) VALUES (:user_id, 0)')->execute([':user_id' => $userId]);
                        $this->states->transitionInTransaction($userId, FinancialPrivacyState::ENCRYPTED);
                        $this->pdo->commit();
                    } catch (\Throwable $e) {
                        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                        throw $e;
                    }
                }
                return $this->response($existing) + ['idempotent' => true];
            }
            throw new HttpException(409, 'VAULT_ALREADY_INITIALIZED', 'Vault is already initialized');
        }

        $vaultId = Str::randomId('vault');
        $this->pdo->beginTransaction();
        try {
            $vault = $this->vaults->create($userId, $vaultId, $data);
            if ($state === FinancialPrivacyState::VAULT_SETUP_REQUIRED) {
                $this->pdo->prepare('INSERT IGNORE INTO encrypted_record_sync_state (user_id, next_sync_sequence) VALUES (:user_id, 0)')->execute([':user_id' => $userId]);
                $this->states->transitionInTransaction($userId, FinancialPrivacyState::ENCRYPTED);
            }
            $this->pdo->commit();
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if (($e->errorInfo[0] ?? '') === '23000') {
                throw new HttpException(409, 'VAULT_ALREADY_INITIALIZED', 'Vault is already initialized');
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        if ($request !== null) {
            $this->audit->record(
                $request,
                $userId,
                $auth->authType,
                'vault.initialized',
                'financial_vault',
                (string) $vault['vault_id'],
                ['encryption_version' => VaultCryptoProfile::VERSION, 'result' => 'created']
            );
        }

        return $this->response($vault);
    }

    public function replacePassphrase(AuthContext $auth, array $payload, ?\App\Http\Request $request = null): array
    {
        $this->recentAuth->requireRecentInteractiveSession($auth);
        $existing = $this->vaults->findByUser($auth->userId());
        if ($existing === null) throw new HttpException(404, 'VAULT_NOT_INITIALIZED', 'Vault is not initialized');
        $wrapper = is_array($payload['passphrase_wrap'] ?? null) ? $payload['passphrase_wrap'] : [];
        $data = $this->validatePassphraseWrapper($wrapper);
        $vault = $this->vaults->replacePassphraseWrapper($auth->userId(), $data);
        if ($request !== null) {
            $this->audit->record(
                $request,
                $auth->userId(),
                $auth->authType,
                'vault.passphrase_rotated',
                'financial_vault',
                (string) $vault['vault_id'],
                ['result' => 'updated']
            );
        }
        return $this->response($vault);
    }

    public function replaceRecovery(AuthContext $auth, array $payload, ?\App\Http\Request $request = null): array
    {
        $this->recentAuth->requireRecentInteractiveSession($auth);
        $existing = $this->vaults->findByUser($auth->userId());
        if ($existing === null) throw new HttpException(404, 'VAULT_NOT_INITIALIZED', 'Vault is not initialized');
        $recovery = is_array($payload['recovery_wrap'] ?? null) ? $payload['recovery_wrap'] : [];
        $wrapped = $this->decodeBinary($recovery['wrapped_vault_key'] ?? null, 'wrapped_vault_key', 40, 512);
        if (($recovery['wrap_algorithm'] ?? null) !== VaultCryptoProfile::WRAP_ALGORITHM) throw new HttpException(422, 'VAULT_PAYLOAD_INVALID', 'Vault payload is structurally invalid');
        $vault = $this->vaults->replaceRecoveryWrapper($auth->userId(), $wrapped);
        if ($request !== null) {
            $this->audit->record(
                $request,
                $auth->userId(),
                $auth->authType,
                'vault.recovery_rotated',
                'financial_vault',
                (string) $vault['vault_id'],
                ['result' => 'updated']
            );
        }
        return $this->response($vault);
    }

    /** @return array<string,mixed> */
    private function response(array $vault): array
    {
        return [
            'vault_id' => (string) $vault['vault_id'],
            'crypto_profile_version' => (int) $vault['crypto_profile_version'],
            'passphrase' => [
                'kdf' => (string) $vault['passphrase_kdf'],
                'kdf_hash' => (string) $vault['passphrase_kdf_hash'],
                'iterations' => (int) $vault['passphrase_kdf_iterations'],
                'salt' => self::base64UrlEncode((string) $vault['passphrase_kdf_salt']),
                'wrap_algorithm' => (string) $vault['passphrase_wrap_algorithm'],
                'wrapped_vault_key' => self::base64UrlEncode((string) $vault['passphrase_wrapped_vault_key']),
            ],
            'recovery' => [
                'wrap_algorithm' => (string) $vault['recovery_wrap_algorithm'],
                'wrapped_vault_key' => self::base64UrlEncode((string) $vault['recovery_wrapped_vault_key']),
            ],
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function validatePayload(array $payload): array
    {
        if ((int) ($payload['crypto_profile_version'] ?? 0) !== VaultCryptoProfile::VERSION) {
            throw new HttpException(422, 'VAULT_PROFILE_UNSUPPORTED', 'Unsupported Vault crypto profile');
        }
        $passphrase = is_array($payload['passphrase_wrap'] ?? null) ? $payload['passphrase_wrap'] : [];
        $recovery = is_array($payload['recovery_wrap'] ?? null) ? $payload['recovery_wrap'] : [];
        $passphraseData = $this->validatePassphraseWrapper($passphrase);
        $recoveryWrapped = $this->decodeBinary($recovery['wrapped_vault_key'] ?? null, 'wrapped_vault_key', 40, 512);
        if (($recovery['wrap_algorithm'] ?? null) !== VaultCryptoProfile::WRAP_ALGORITHM) {
            throw new HttpException(422, 'VAULT_PAYLOAD_INVALID', 'Vault payload is structurally invalid');
        }

        return [
            'crypto_profile_version' => VaultCryptoProfile::VERSION,
            ...$passphraseData,
            'recovery_wrap_algorithm' => VaultCryptoProfile::WRAP_ALGORITHM,
            'recovery_wrapped_vault_key' => $recoveryWrapped,
        ];
    }

    private function validatePassphraseWrapper(array $passphrase): array
    {
        $salt = $this->decodeBinary($passphrase['salt'] ?? null, 'salt', VaultCryptoProfile::SALT_BYTES, VaultCryptoProfile::SALT_BYTES);
        $wrapped = $this->decodeBinary($passphrase['wrapped_vault_key'] ?? null, 'wrapped_vault_key', 40, 512);
        $iterations = (int) ($passphrase['iterations'] ?? 0);
        if ($iterations < VaultCryptoProfile::KDF_ITERATIONS || $iterations > 5000000 || ($passphrase['kdf'] ?? null) !== VaultCryptoProfile::PASSPHRASE_KDF || ($passphrase['kdf_hash'] ?? null) !== VaultCryptoProfile::KDF_HASH || ($passphrase['wrap_algorithm'] ?? null) !== VaultCryptoProfile::WRAP_ALGORITHM) throw new HttpException(422, 'VAULT_PAYLOAD_INVALID', 'Vault payload is structurally invalid');
        return ['passphrase_kdf' => VaultCryptoProfile::PASSPHRASE_KDF, 'passphrase_kdf_hash' => VaultCryptoProfile::KDF_HASH, 'passphrase_kdf_iterations' => $iterations, 'passphrase_wrap_algorithm' => VaultCryptoProfile::WRAP_ALGORITHM, 'passphrase_kdf_salt' => $salt, 'passphrase_wrapped_vault_key' => $wrapped];
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $data */
    private function matches(array $existing, array $data): bool
    {
        return (int) $existing['crypto_profile_version'] === (int) $data['crypto_profile_version']
            && (int) $existing['passphrase_kdf_iterations'] === (int) $data['passphrase_kdf_iterations']
            && hash_equals((string) $existing['passphrase_kdf_salt'], (string) $data['passphrase_kdf_salt'])
            && hash_equals((string) $existing['passphrase_wrapped_vault_key'], (string) $data['passphrase_wrapped_vault_key'])
            && hash_equals((string) $existing['recovery_wrapped_vault_key'], (string) $data['recovery_wrapped_vault_key']);
    }

    private function decodeBinary(mixed $value, string $field, int $minBytes, int $maxBytes): string
    {
        if (!is_string($value) || $value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new HttpException(422, 'VAULT_PAYLOAD_INVALID', 'Vault payload is structurally invalid');
        }
        $padded = strtr($value, '-_', '+/');
        $padding = strlen($padded) % 4;
        if ($padding !== 0) {
            $padded .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($padded, true);
        if ($decoded === false || strlen($decoded) < $minBytes || strlen($decoded) > $maxBytes) {
            throw new HttpException(422, 'VAULT_PAYLOAD_INVALID', 'Vault payload is structurally invalid');
        }
        return $decoded;
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
