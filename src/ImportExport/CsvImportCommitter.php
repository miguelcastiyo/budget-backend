<?php

declare(strict_types=1);

namespace App\ImportExport;

use App\Support\Str;
use PDO;

final class CsvImportCommitter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TaxonomyImportRepository $taxonomy,
        private readonly CsvImportMapper $mapper
    ) {
    }

    /** @param array<int,array<string,mixed>> $parsedRows */
    public function estimateDryRunDuplicates(int $userId, array $parsedRows): int
    {
        $resolvedRows = $this->resolveRows($userId, $parsedRows, createMissing: false);
        $duplicateKeys = $this->existingDuplicateKeys($userId, $resolvedRows);
        $count = 0;

        foreach ($resolvedRows as $row) {
            if (($row['tag_id'] ?? null) === null) {
                continue;
            }
            if (isset($duplicateKeys[$this->duplicateKey($row)])) {
                $count++;
            }
        }

        return $count;
    }

    /** @param array<int,array<string,mixed>> $parsedRows */
    public function commitRows(int $userId, array $parsedRows, int $importRunId): array
    {
        $resolvedRows = $this->resolveRows($userId, $parsedRows, createMissing: true);
        $duplicateKeys = $this->existingDuplicateKeys($userId, $resolvedRows);
        $importedRows = 0;
        $duplicateRows = 0;

        foreach ($resolvedRows as $row) {
            $key = $this->duplicateKey($row);
            if (isset($duplicateKeys[$key])) {
                $duplicateRows++;
                continue;
            }

            if ($this->insertImportedRow($userId, $row, $importRunId)) {
                $importedRows++;
                $duplicateKeys[$key] = true;
            } else {
                $duplicateRows++;
            }
        }

        return [
            'imported_rows' => $importedRows,
            'duplicate_rows' => $duplicateRows,
        ];
    }

    /** @param array<int,array<string,mixed>> $parsedRows */
    public function plannedNewTags(int $userId, array $parsedRows): array
    {
        $names = [];
        foreach ($parsedRows as $parsed) {
            if (($parsed['tag_id'] ?? null) === null) {
                $name = trim((string) ($parsed['tag_name'] ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        $activeTags = $this->taxonomy->activeTagsByNames($userId, $names);
        $items = [];
        $seen = [];
        foreach ($names as $name) {
            $key = mb_strtolower(trim($name));
            if ($key === '' || isset($seen[$key]) || isset($activeTags[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = [
                'name' => trim($name),
                'icon_key' => $this->mapper->inferredTagIconKey($name),
            ];
        }

        return $items;
    }

    /** @param array<int,array<string,mixed>> $parsedRows */
    public function plannedNewCards(int $userId, array $parsedRows): array
    {
        $names = [];
        foreach ($parsedRows as $parsed) {
            $name = trim((string) ($parsed['card_name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        $activeCards = $this->taxonomy->activeCardsByNames($userId, $names);
        $items = [];
        $seen = [];
        foreach ($names as $name) {
            $key = mb_strtolower(trim($name));
            if ($key === '' || isset($seen[$key]) || isset($activeCards[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = ['name' => trim($name)];
        }

        return $items;
    }

    /** @param array<int,array<string,mixed>> $parsedRows */
    private function resolveRows(int $userId, array $parsedRows, bool $createMissing): array
    {
        $tagNames = [];
        $cardNames = [];
        foreach ($parsedRows as $row) {
            if (($row['tag_id'] ?? null) === null) {
                $tagName = trim((string) ($row['tag_name'] ?? ''));
                if ($tagName !== '') {
                    $tagNames[] = $tagName;
                }
            }
            $cardName = trim((string) ($row['card_name'] ?? ''));
            if ($cardName !== '') {
                $cardNames[] = $cardName;
            }
        }

        $activeTags = $this->taxonomy->activeTagsByNames($userId, $tagNames);
        $activeCards = $this->taxonomy->activeCardsByNames($userId, $cardNames);

        $resolved = [];
        foreach ($parsedRows as $row) {
            $tagId = $row['tag_id'] !== null ? (int) $row['tag_id'] : null;
            $tagName = trim((string) ($row['tag_name'] ?? ''));
            if ($tagId === null && $tagName !== '') {
                $tagKey = mb_strtolower($tagName);
                if (isset($activeTags[$tagKey])) {
                    $tagId = $activeTags[$tagKey]['id'];
                } elseif ($createMissing) {
                    $tagId = $this->taxonomy->findOrCreateTag($userId, $tagName);
                    $activeTags[$tagKey] = ['id' => $tagId, 'name' => $tagName];
                }
            }

            $cardId = null;
            $cardName = trim((string) ($row['card_name'] ?? ''));
            if ($cardName !== '') {
                $cardKey = mb_strtolower($cardName);
                if (isset($activeCards[$cardKey])) {
                    $cardId = $activeCards[$cardKey]['id'];
                } elseif ($createMissing) {
                    $cardId = $this->taxonomy->findOrCreateCard($userId, $cardName);
                    $activeCards[$cardKey] = ['id' => $cardId, 'name' => $cardName];
                }
            }

            $row['tag_id'] = $tagId;
            $row['card_id'] = $cardId;
            $row['import_fingerprint'] = $tagId === null
                ? null
                : $this->buildImportFingerprint(
                    date: (string) $row['date'],
                    amount: (string) $row['amount'],
                    expense: (string) $row['expense'],
                    category: (string) $row['category'],
                    isSplit: (bool) $row['is_split'],
                    tagId: $tagId,
                    cardId: $cardId
                );
            $resolved[] = $row;
        }

        return $resolved;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string,true> */
    private function existingDuplicateKeys(int $userId, array $rows): array
    {
        $dates = [];
        foreach ($rows as $row) {
            if (($row['tag_id'] ?? null) !== null) {
                $dates[(string) $row['date']] = true;
            }
        }
        if ($dates === []) {
            return [];
        }

        $holders = [];
        $params = [':user_id' => $userId];
        foreach (array_keys($dates) as $i => $date) {
            $key = ':date_' . $i;
            $holders[] = $key;
            $params[$key] = $date;
        }

        $sql = "SELECT transaction_date, amount, expense, category, is_split, tag_id, card_id
                FROM transactions
                WHERE user_id = :user_id
                  AND deleted_at IS NULL
                  AND transaction_date IN (" . implode(', ', $holders) . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $keys = [];
        foreach ($stmt->fetchAll() as $row) {
            $keys[$this->duplicateKey([
                'date' => (string) $row['transaction_date'],
                'amount' => $this->fmt((string) $row['amount']),
                'expense' => (string) $row['expense'],
                'category' => (string) $row['category'],
                'is_split' => ((int) $row['is_split']) === 1,
                'tag_id' => (int) $row['tag_id'],
                'card_id' => $row['card_id'] === null ? null : (int) $row['card_id'],
            ])] = true;
        }

        return $keys;
    }

    private function insertImportedRow(int $userId, array $row, int $importRunId): bool
    {
        $sql = <<<'SQL'
INSERT INTO transactions (
  user_id,
  transaction_date,
  expense,
  amount,
  category,
  is_split,
  tag_id,
  card_id,
  source,
  import_fingerprint,
  csv_import_run_id
)
VALUES (
  :user_id,
  :transaction_date,
  :expense,
  :amount,
  :category,
  :is_split,
  :tag_id,
  :card_id,
  'import',
  :import_fingerprint,
  :csv_import_run_id
)
SQL;

        $stmt = $this->pdo->prepare($sql);

        try {
            $stmt->execute([
                ':user_id' => $userId,
                ':transaction_date' => (string) $row['date'],
                ':expense' => (string) $row['expense'],
                ':amount' => (string) $row['amount'],
                ':category' => (string) $row['category'],
                ':is_split' => ((bool) $row['is_split']) ? 1 : 0,
                ':tag_id' => (int) $row['tag_id'],
                ':card_id' => $row['card_id'],
                ':import_fingerprint' => (string) $row['import_fingerprint'],
                ':csv_import_run_id' => $importRunId,
            ]);

            return true;
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? '') === '23000') {
                return false;
            }
            throw $e;
        }
    }

    private function duplicateKey(array $row): string
    {
        return implode('|', [
            (string) $row['date'],
            $this->fmt((string) $row['amount']),
            strtolower(trim(preg_replace('/\s+/', ' ', (string) $row['expense']) ?? (string) $row['expense'])),
            (string) $row['category'],
            ((bool) $row['is_split']) ? '1' : '0',
            (string) $row['tag_id'],
            $row['card_id'] === null ? '' : (string) $row['card_id'],
        ]);
    }

    private function buildImportFingerprint(
        string $date,
        string $amount,
        string $expense,
        string $category,
        bool $isSplit,
        int $tagId,
        ?int $cardId
    ): string {
        $normalizedExpense = strtolower(trim(preg_replace('/\s+/', ' ', $expense) ?? $expense));
        $key = implode('|', [
            $date,
            $amount,
            $normalizedExpense,
            $category,
            $isSplit ? '1' : '0',
            (string) $tagId,
            $cardId === null ? '' : (string) $cardId,
        ]);

        return Str::hashSha256($key);
    }

    private function fmt(string $decimal): string
    {
        return number_format((float) $decimal, 2, '.', '');
    }
}
