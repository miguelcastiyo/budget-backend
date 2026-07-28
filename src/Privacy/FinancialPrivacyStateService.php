<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Http\HttpException;
use PDO;
use Throwable;

final class FinancialPrivacyStateService
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'vault_setup_required' => ['encrypted'],
        'legacy_plaintext' => ['migration_in_progress'],
        'migration_in_progress' => ['encrypted', 'migration_failed', 'legacy_plaintext'],
        'migration_failed' => ['migration_in_progress', 'legacy_plaintext'],
        'encrypted' => [],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(int $userId): FinancialPrivacyState
    {
        $stmt = $this->pdo->prepare('SELECT financial_privacy_state FROM users WHERE id = :user_id LIMIT 1');
        $stmt->execute([':user_id' => $userId]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            throw new HttpException(404, 'USER_NOT_FOUND', 'User not found');
        }

        return FinancialPrivacyState::fromDatabase($value);
    }

    public function requireLegacyPlaintextAuthority(int $userId): void
    {
        if ($this->get($userId) !== FinancialPrivacyState::LEGACY_PLAINTEXT) {
            throw new HttpException(409, 'PRIVACY_STATE_CONFLICT', 'Vault initialization is only available for legacy plaintext accounts');
        }
    }

    public function requireEncryptedAuthority(int $userId): void
    {
        if ($this->get($userId) !== FinancialPrivacyState::ENCRYPTED) {
            throw new HttpException(409, 'PRIVACY_STATE_CONFLICT', 'Encrypted financial authority is not enabled for this account');
        }
    }

    public function transition(int $userId, FinancialPrivacyState $to): FinancialPrivacyState
    {
        $from = $this->get($userId);
        if (!in_array($to->value, self::TRANSITIONS[$from->value], true)) {
            throw new HttpException(409, 'PRIVACY_STATE_CONFLICT', 'Financial privacy state transition is not allowed');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE users SET financial_privacy_state = :to_state WHERE id = :user_id AND financial_privacy_state = :from_state'
        );
        $stmt->execute([
            ':to_state' => $to->value,
            ':user_id' => $userId,
            ':from_state' => $from->value,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new HttpException(409, 'PRIVACY_STATE_CONFLICT', 'Financial privacy state changed concurrently');
        }

        return $to;
    }

    public function transitionInTransaction(int $userId, FinancialPrivacyState $to): FinancialPrivacyState
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare('SELECT financial_privacy_state FROM users WHERE id = :user_id FOR UPDATE');
            $stmt->execute([':user_id' => $userId]);
            $value = $stmt->fetchColumn();
            if ($value === false) {
                throw new HttpException(404, 'USER_NOT_FOUND', 'User not found');
            }
            $from = FinancialPrivacyState::fromDatabase($value);
            if (!in_array($to->value, self::TRANSITIONS[$from->value], true)) {
                throw new HttpException(409, 'PRIVACY_STATE_CONFLICT', 'Financial privacy state transition is not allowed');
            }
            $update = $this->pdo->prepare('UPDATE users SET financial_privacy_state = :to_state WHERE id = :user_id');
            $update->execute([':to_state' => $to->value, ':user_id' => $userId]);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $to;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
