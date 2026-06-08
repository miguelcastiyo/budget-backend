<?php

declare(strict_types=1);

namespace App\ImportExport;

use App\Http\HttpException;
use PDO;

final class DataRunRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function listDataRuns(int $userId, mixed $limitRaw): array
    {
        $limit = $this->clampedDataRunsLimit($limitRaw);
        $sql = <<<'SQL'
SELECT *
FROM (
  SELECT
    ci.id AS run_id,
    CAST(CONCAT('import_', ci.id) AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS id,
    CAST('import' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS type,
    CASE
      WHEN ci.invalid_rows = 0 THEN CAST('completed' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci
      WHEN ci.valid_rows > 0 AND ci.invalid_rows > 0 THEN CAST('partial' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci
      ELSE CAST('failed' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci
    END AS status,
    ci.created_at,
    CAST(ci.source_filename AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS source_filename,
    NULL AS date_from,
    NULL AS date_to,
    ci.total_rows,
    ci.valid_rows,
    ci.imported_rows,
    ci.duplicate_rows,
    ci.invalid_rows,
    ci.skipped_rows,
    ci.skipped_blank_amount_rows,
    CAST(ci.error_summary AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS error_summary,
    ci.rolled_back_at,
    ci.rolled_back_rows,
    COALESCE(ir.linked_rows, 0) AS rollback_linked_rows,
    COALESCE(ir.active_rows, 0) AS rollback_active_rows
  FROM csv_import_runs ci
  LEFT JOIN (
    SELECT
      user_id,
      csv_import_run_id,
      COUNT(*) AS linked_rows,
      SUM(CASE WHEN deleted_at IS NULL THEN 1 ELSE 0 END) AS active_rows
    FROM transactions
    WHERE source = 'import'
      AND csv_import_run_id IS NOT NULL
    GROUP BY user_id, csv_import_run_id
  ) ir ON ir.user_id = ci.user_id AND ir.csv_import_run_id = ci.id
  WHERE ci.user_id = :import_user_id
    AND ci.mode = 'commit'

  UNION ALL

  SELECT
    ce.id AS run_id,
    CAST(CONCAT('export_', ce.id) AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS id,
    CAST('export' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS type,
    CAST(ce.status AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS status,
    ce.created_at,
    CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS source_filename,
    ce.date_from,
    ce.date_to,
    ce.total_rows,
    NULL AS valid_rows,
    NULL AS imported_rows,
    NULL AS duplicate_rows,
    NULL AS invalid_rows,
    NULL AS skipped_rows,
    NULL AS skipped_blank_amount_rows,
    CAST(ce.error_summary AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS error_summary,
    NULL AS rolled_back_at,
    0 AS rolled_back_rows,
    0 AS rollback_linked_rows,
    0 AS rollback_active_rows
  FROM csv_export_runs ce
  WHERE ce.user_id = :export_user_id
) runs
ORDER BY created_at DESC, run_id DESC
LIMIT :limit
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':import_user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':export_user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->dataRunItem($row);
        }

        return $items;
    }

    public function rollbackImport(int $userId, int $importRunId): array
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $run = $this->fetchImportRunForRollback($userId, $importRunId);
            if ($run['rolled_back_at'] !== null) {
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }

                return [
                    'status' => 'rolled_back',
                    'import_run_id' => (string) $importRunId,
                    'deleted_rows' => (int) $run['rolled_back_rows'],
                ];
            }

            $linkedRows = $this->countLinkedImportRows($userId, $importRunId, includeDeleted: true);
            if ($linkedRows === 0) {
                if ($ownsTransaction) {
                    $this->pdo->rollBack();
                }
                throw new HttpException(409, 'ROLLBACK_UNAVAILABLE', 'Rollback unavailable for imports before this feature.');
            }

            $delete = $this->pdo->prepare(
                "UPDATE transactions
                 SET deleted_at = UTC_TIMESTAMP(), updated_at = CURRENT_TIMESTAMP
                 WHERE user_id = :user_id
                   AND source = 'import'
                   AND csv_import_run_id = :import_run_id
                   AND deleted_at IS NULL"
            );
            $delete->execute([
                ':user_id' => $userId,
                ':import_run_id' => $importRunId,
            ]);
            $deletedRows = $delete->rowCount();

            $mark = $this->pdo->prepare(
                'UPDATE csv_import_runs
                 SET rolled_back_at = UTC_TIMESTAMP(), rolled_back_rows = :rolled_back_rows
                 WHERE id = :id AND user_id = :user_id AND rolled_back_at IS NULL'
            );
            $mark->execute([
                ':id' => $importRunId,
                ':user_id' => $userId,
                ':rolled_back_rows' => $deletedRows,
            ]);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'status' => 'rolled_back',
            'import_run_id' => (string) $importRunId,
            'deleted_rows' => $deletedRows,
        ];
    }

    public function recordImportRun(
        int $userId,
        string $mode,
        string $status,
        string $sourceFilename,
        int $totalRows,
        int $validRows,
        int $importedRows,
        int $duplicateRows,
        int $invalidRows,
        int $skippedRows,
        int $skippedBlankAmountRows,
        ?string $errorSummary
    ): int {
        $sql = <<<'SQL'
INSERT INTO csv_import_runs (
  user_id,
  mode,
  status,
  source_filename,
  total_rows,
  valid_rows,
  imported_rows,
  duplicate_rows,
  invalid_rows,
  skipped_rows,
  skipped_blank_amount_rows,
  error_summary
)
VALUES (
  :user_id,
  :mode,
  :status,
  :source_filename,
  :total_rows,
  :valid_rows,
  :imported_rows,
  :duplicate_rows,
  :invalid_rows,
  :skipped_rows,
  :skipped_blank_amount_rows,
  :error_summary
)
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':mode' => $mode,
            ':status' => $status,
            ':source_filename' => $sourceFilename,
            ':total_rows' => $totalRows,
            ':valid_rows' => $validRows,
            ':imported_rows' => $importedRows,
            ':duplicate_rows' => $duplicateRows,
            ':invalid_rows' => $invalidRows,
            ':skipped_rows' => $skippedRows,
            ':skipped_blank_amount_rows' => $skippedBlankAmountRows,
            ':error_summary' => $errorSummary,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function completeImportRun(
        int $importRunId,
        int $userId,
        string $status,
        int $importedRows,
        int $duplicateRows,
        int $skippedRows,
        int $skippedBlankAmountRows,
        ?string $errorSummary
    ): void {
        $sql = <<<'SQL'
UPDATE csv_import_runs
SET
  status = :status,
  imported_rows = :imported_rows,
  duplicate_rows = :duplicate_rows,
  skipped_rows = :skipped_rows,
  skipped_blank_amount_rows = :skipped_blank_amount_rows,
  error_summary = :error_summary
WHERE id = :id AND user_id = :user_id
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $importRunId,
            ':user_id' => $userId,
            ':status' => $status,
            ':imported_rows' => $importedRows,
            ':duplicate_rows' => $duplicateRows,
            ':skipped_rows' => $skippedRows,
            ':skipped_blank_amount_rows' => $skippedBlankAmountRows,
            ':error_summary' => $errorSummary,
        ]);
    }

    public function recordExportRun(int $userId, ?string $dateFrom, ?string $dateTo): int
    {
        $sql = <<<'SQL'
INSERT INTO csv_export_runs (
  user_id,
  status,
  date_from,
  date_to,
  total_rows
)
VALUES (
  :user_id,
  'started',
  :date_from,
  :date_to,
  0
)
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':date_from' => $dateFrom,
            ':date_to' => $dateTo,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function completeExportRun(int $exportRunId, string $status, int $totalRows, ?string $errorSummary = null): void
    {
        $sql = <<<'SQL'
UPDATE csv_export_runs
SET
  status = :status,
  total_rows = :total_rows,
  error_summary = :error_summary,
  completed_at = UTC_TIMESTAMP()
WHERE id = :id
SQL;

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':id' => $exportRunId,
                ':status' => $status,
                ':total_rows' => $totalRows,
                ':error_summary' => $errorSummary,
            ]);
        } catch (\Throwable) {
            // Preserve the CSV download path if the activity update fails after streaming starts.
        }
    }

    public function clampedDataRunsLimit(mixed $value): int
    {
        if ($value === null || trim((string) $value) === '') {
            return 50;
        }

        return min(100, max(1, (int) $value));
    }

    /** @param array<string,mixed> $row */
    public function dataRunItem(array $row): array
    {
        $type = (string) $row['type'];
        $importedRows = $row['imported_rows'] !== null ? (int) $row['imported_rows'] : null;
        $rolledBackAtRaw = $row['rolled_back_at'] ?? null;
        $rolledBackAt = $rolledBackAtRaw !== null ? (string) $rolledBackAtRaw : null;
        $rollbackLinkedRows = (int) ($row['rollback_linked_rows'] ?? 0);
        $rollbackActiveRows = (int) ($row['rollback_active_rows'] ?? 0);

        return [
            'id' => (string) $row['id'],
            'type' => $type,
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
            'source_filename' => $row['source_filename'] !== null ? (string) $row['source_filename'] : null,
            'date_from' => $row['date_from'] !== null ? (string) $row['date_from'] : null,
            'date_to' => $row['date_to'] !== null ? (string) $row['date_to'] : null,
            'total_rows' => (int) $row['total_rows'],
            'valid_rows' => $row['valid_rows'] !== null ? (int) $row['valid_rows'] : null,
            'imported_rows' => $importedRows,
            'duplicate_rows' => $row['duplicate_rows'] !== null ? (int) $row['duplicate_rows'] : null,
            'invalid_rows' => $row['invalid_rows'] !== null ? (int) $row['invalid_rows'] : null,
            'skipped_rows' => $row['skipped_rows'] !== null ? (int) $row['skipped_rows'] : null,
            'skipped_blank_amount_rows' => $row['skipped_blank_amount_rows'] !== null ? (int) $row['skipped_blank_amount_rows'] : null,
            'error_summary' => $row['error_summary'] !== null ? (string) $row['error_summary'] : null,
            'rollback_available' => $type === 'import'
                && $rolledBackAt === null
                && ($importedRows ?? 0) > 0
                && $rollbackActiveRows > 0,
            'rolled_back_at' => $rolledBackAt,
            'rolled_back_rows' => (int) ($row['rolled_back_rows'] ?? 0),
            'rollback_unavailable_reason' => $type === 'import'
                && $rolledBackAt === null
                && ($importedRows ?? 0) > 0
                && $rollbackLinkedRows === 0
                    ? 'pre_rollback_feature'
                    : null,
        ];
    }

    private function fetchImportRunForRollback(int $userId, int $importRunId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, imported_rows, rolled_back_at, rolled_back_rows
             FROM csv_import_runs
             WHERE id = :id AND user_id = :user_id AND mode = 'commit'
             LIMIT 1"
        );
        $stmt->execute([
            ':id' => $importRunId,
            ':user_id' => $userId,
        ]);

        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException(404, 'NOT_FOUND', 'Import run not found');
        }

        return $row;
    }

    private function countLinkedImportRows(int $userId, int $importRunId, bool $includeDeleted): int
    {
        $sql = "SELECT COUNT(*) AS total
                FROM transactions
                WHERE user_id = :user_id
                  AND source = 'import'
                  AND csv_import_run_id = :import_run_id";
        if (!$includeDeleted) {
            $sql .= ' AND deleted_at IS NULL';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':import_run_id' => $importRunId,
        ]);

        return (int) ($stmt->fetch()['total'] ?? 0);
    }
}
