<?php

declare(strict_types=1);

namespace App\Privacy;

use PDO;

final class EncryptedRecordRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function ensureSyncState(int $userId): void
    {
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO encrypted_record_sync_state (user_id, next_sync_sequence) VALUES (:user_id, 0)');
        $stmt->execute([':user_id' => $userId]);
    }

    public function nextSyncSequence(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT next_sync_sequence FROM encrypted_record_sync_state WHERE user_id = :user_id FOR UPDATE');
        $stmt->execute([':user_id' => $userId]);
        $current = $stmt->fetchColumn();
        if ($current === false) throw new \RuntimeException('Encrypted sync state was not initialized');
        $next = (int) $current + 1;
        $update = $this->pdo->prepare('UPDATE encrypted_record_sync_state SET next_sync_sequence = :next_sequence WHERE user_id = :user_id');
        $update->execute([':next_sequence' => $next, ':user_id' => $userId]);
        return $next;
    }

    public function currentSyncSequence(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT next_sync_sequence FROM encrypted_record_sync_state WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId]);
        $value = $stmt->fetchColumn();
        return $value === false ? 0 : (int) $value;
    }

    /** @return array<string,mixed>|null */
    public function findForUpdate(int $userId, string $recordId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM encrypted_financial_records WHERE user_id = :user_id AND record_id = :record_id LIMIT 1 FOR UPDATE');
        $stmt->execute([':user_id' => $userId, ':record_id' => $recordId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $data */
    public function insert(int $userId, array $data): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO encrypted_financial_records (user_id, vault_id, record_id, envelope_version, record_revision, iv, ciphertext, sync_sequence, is_deleted, last_mutation_id, last_mutation_digest) VALUES (:user_id, :vault_id, :record_id, :version, :revision, :iv, :ciphertext, :sequence, 0, :mutation_id, :digest)');
        $stmt->execute([
            ':user_id' => $userId, ':vault_id' => $data['vault_id'], ':record_id' => $data['record_id'],
            ':version' => $data['envelope_version'], ':revision' => $data['record_revision'], ':iv' => $data['iv'],
            ':ciphertext' => $data['ciphertext'], ':sequence' => $data['sync_sequence'], ':mutation_id' => $data['mutation_id'], ':digest' => $data['digest'],
        ]);
    }

    /** @param array<string,mixed> $data */
    public function appendChange(int $userId, array $data): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO encrypted_record_changes (user_id, vault_id, record_id, envelope_version, record_revision, iv, ciphertext, sync_sequence, is_deleted) VALUES (:user_id, :vault_id, :record_id, :version, :revision, :iv, :ciphertext, :sequence, :deleted)');
        $stmt->execute([
            ':user_id' => $userId, ':vault_id' => $data['vault_id'], ':record_id' => $data['record_id'], ':version' => $data['envelope_version'],
            ':revision' => $data['record_revision'], ':iv' => $data['iv'] ?? null, ':ciphertext' => $data['ciphertext'] ?? null,
            ':sequence' => $data['sync_sequence'], ':deleted' => (int) ($data['is_deleted'] ?? 0),
        ]);
    }

    /** @param array<string,mixed> $data */
    public function update(int $userId, string $recordId, array $data): void
    {
        $stmt = $this->pdo->prepare('UPDATE encrypted_financial_records SET envelope_version = :version, record_revision = :revision, iv = :iv, ciphertext = :ciphertext, sync_sequence = :sequence, is_deleted = 0, deleted_at = NULL, last_mutation_id = :mutation_id, last_mutation_digest = :digest WHERE user_id = :user_id AND record_id = :record_id');
        $stmt->execute([
            ':version' => $data['envelope_version'], ':revision' => $data['record_revision'], ':iv' => $data['iv'], ':ciphertext' => $data['ciphertext'],
            ':sequence' => $data['sync_sequence'], ':mutation_id' => $data['mutation_id'], ':digest' => $data['digest'], ':user_id' => $userId, ':record_id' => $recordId,
        ]);
    }

    /** @param array<string,mixed> $data */
    public function tombstone(int $userId, string $recordId, array $data): void
    {
        $stmt = $this->pdo->prepare('UPDATE encrypted_financial_records SET record_revision = :revision, iv = NULL, ciphertext = NULL, sync_sequence = :sequence, is_deleted = 1, deleted_at = UTC_TIMESTAMP(), last_mutation_id = :mutation_id, last_mutation_digest = :digest WHERE user_id = :user_id AND record_id = :record_id');
        $stmt->execute([
            ':revision' => $data['record_revision'], ':sequence' => $data['sync_sequence'], ':mutation_id' => $data['mutation_id'], ':digest' => $data['digest'], ':user_id' => $userId, ':record_id' => $recordId,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function syncAfter(int $userId, int $cursor, int $limit): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM encrypted_record_changes WHERE user_id = :user_id AND sync_sequence > :cursor ORDER BY sync_sequence ASC LIMIT :limit');
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':cursor', $cursor, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $userId, string $recordId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM encrypted_financial_records WHERE user_id = :user_id AND record_id = :record_id LIMIT 1');
        $stmt->execute([':user_id' => $userId, ':record_id' => $recordId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }
}
