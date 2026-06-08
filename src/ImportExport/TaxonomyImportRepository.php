<?php

declare(strict_types=1);

namespace App\ImportExport;

use PDO;

final class TaxonomyImportRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CsvImportMapper $mapper
    ) {
    }

    /** @param list<int> $tagIds @return array<int,string> */
    public function activeTagNamesByIds(int $userId, array $tagIds): array
    {
        $tagIds = array_values(array_unique(array_filter($tagIds, static fn(int $id): bool => $id > 0)));
        if ($tagIds === []) {
            return [];
        }

        $holders = [];
        $params = [':user_id' => $userId];
        foreach ($tagIds as $i => $id) {
            $key = ':tag_id_' . $i;
            $holders[] = $key;
            $params[$key] = $id;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, name FROM tags WHERE user_id = :user_id AND id IN (' . implode(', ', $holders) . ') AND is_active = 1 AND deleted_at IS NULL'
        );
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[(int) $row['id']] = (string) $row['name'];
        }

        return $items;
    }

    /** @param list<string> $names @return array<string,array{id:int,name:string}> */
    public function activeTagsByNames(int $userId, array $names): array
    {
        return $this->activeNamedEntitiesByNames('tags', $userId, $names);
    }

    /** @param list<string> $names @return array<string,array{id:int,name:string}> */
    public function activeCardsByNames(int $userId, array $names): array
    {
        return $this->activeNamedEntitiesByNames('cards', $userId, $names);
    }

    public function findOrCreateTag(int $userId, string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id, is_active, deleted_at, icon_key FROM tags WHERE user_id = :user_id AND LOWER(name) = LOWER(:name) LIMIT 1');
        $stmt->execute([
            ':user_id' => $userId,
            ':name' => $name,
        ]);
        $row = $stmt->fetch();

        if ($row) {
            if ((int) $row['is_active'] === 0 || $row['deleted_at'] !== null) {
                $reactivate = $this->pdo->prepare(
                    'UPDATE tags SET is_active = 1, deleted_at = NULL, icon_key = COALESCE(icon_key, :icon_key), updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id'
                );
                $reactivate->execute([
                    ':id' => $row['id'],
                    ':user_id' => $userId,
                    ':icon_key' => $this->mapper->inferredTagIconKey($name),
                ]);
            }

            return (int) $row['id'];
        }

        $insert = $this->pdo->prepare('INSERT INTO tags (user_id, name, icon_key, is_active) VALUES (:user_id, :name, :icon_key, 1)');
        $insert->execute([
            ':user_id' => $userId,
            ':name' => $name,
            ':icon_key' => $this->mapper->inferredTagIconKey($name),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findOrCreateCard(int $userId, string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id, is_active, deleted_at FROM cards WHERE user_id = :user_id AND LOWER(name) = LOWER(:name) LIMIT 1');
        $stmt->execute([
            ':user_id' => $userId,
            ':name' => $name,
        ]);
        $row = $stmt->fetch();

        if ($row) {
            if ((int) $row['is_active'] === 0 || $row['deleted_at'] !== null) {
                $reactivate = $this->pdo->prepare(
                    'UPDATE cards SET is_active = 1, deleted_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id'
                );
                $reactivate->execute([
                    ':id' => $row['id'],
                    ':user_id' => $userId,
                ]);
            }

            return (int) $row['id'];
        }

        $insert = $this->pdo->prepare('INSERT INTO cards (user_id, name, is_active) VALUES (:user_id, :name, 1)');
        $insert->execute([
            ':user_id' => $userId,
            ':name' => $name,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param list<string> $names @return array<string,array{id:int,name:string}> */
    private function activeNamedEntitiesByNames(string $table, int $userId, array $names): array
    {
        $keys = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ($name !== '') {
                $keys[mb_strtolower($name)] = true;
            }
        }
        $names = array_keys($keys);
        if ($names === []) {
            return [];
        }

        $holders = [];
        $params = [':user_id' => $userId];
        foreach ($names as $i => $name) {
            $key = ':name_' . $i;
            $holders[] = $key;
            $params[$key] = $name;
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, name FROM {$table} WHERE user_id = :user_id AND LOWER(name) IN (" . implode(', ', $holders) . ') AND is_active = 1 AND deleted_at IS NULL'
        );
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[mb_strtolower((string) $row['name'])] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }

        return $items;
    }
}
