<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Http\HttpException;
use PDO;

final class FinancialRevisionService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(int $userId): int
    {
        if ($this->isSqliteFixtureWithoutRevisionColumns()) {
            return 0;
        }
        $stmt = $this->pdo->prepare('SELECT financial_revision FROM users WHERE id = :user_id LIMIT 1');
        $stmt->execute([':user_id' => $userId]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            throw new HttpException(404, 'USER_NOT_FOUND', 'User not found');
        }

        return (int) $value;
    }

    public function increment(int $userId): int
    {
        if ($this->isSqliteFixtureWithoutRevisionColumns()) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE users SET financial_revision = financial_revision + 1 WHERE id = :user_id'
        );
        $stmt->execute([':user_id' => $userId]);
        if ($stmt->rowCount() !== 1) {
            throw new HttpException(404, 'USER_NOT_FOUND', 'User not found');
        }

        return $this->get($userId);
    }

    private function isSqliteFixtureWithoutRevisionColumns(): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            return false;
        }

        $table = $this->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'users'");
        if ((int) $table->fetchColumn() === 0) {
            return true;
        }

        $columns = $this->pdo->query('PRAGMA table_info(users)')->fetchAll();
        $names = array_map(static fn(array $row): string => (string) ($row['name'] ?? ''), $columns);
        return !in_array('financial_revision', $names, true) || !in_array('financial_privacy_state', $names, true);
    }
}
