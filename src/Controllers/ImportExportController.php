<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Core\Config;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Support\Str;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class ImportExportController
{
    private const ALLOWED_CATEGORIES = ['needs', 'wants', 'savings_debts'];
    private const DEFAULT_MAX_IMPORT_BYTES = 5242880;
    private const DEFAULT_MAX_IMPORT_ROWS = 5000;
    private const DEFAULT_MAX_IMPORT_ERRORS = 100;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthService $auth,
        private readonly Config $config
    ) {
    }

    public function exportCsv(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        [$dateFrom, $dateTo] = $this->resolveDateRange($request->query);
        [$whereSql, $params] = $this->buildFilterWhere($request->query, $ctx->userId(), [$dateFrom, $dateTo]);

        $sql = <<<'SQL'
SELECT
  t.transaction_date,
  t.expense,
  t.amount,
  t.category,
  t.is_split,
  tg.name AS tag_name,
  c.name AS card_name,
  t.created_at,
  t.updated_at
FROM transactions t
JOIN tags tg ON tg.id = t.tag_id AND tg.user_id = t.user_id
LEFT JOIN cards c ON c.id = t.card_id AND c.user_id = t.user_id
WHERE %s
ORDER BY t.transaction_date DESC, t.id DESC
SQL;

        $stmt = $this->pdo->prepare(sprintf($sql, $whereSql));
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();

        $exportRunId = $this->recordExportRun($ctx->userId(), $dateFrom, $dateTo);
        $filename = 'transactions_' . gmdate('Ymd_His') . '.csv';

        return Response::stream(function () use ($stmt, $exportRunId): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                $this->completeExportRun($exportRunId, 'failed', 0, 'Could not open output stream');
                throw new \RuntimeException('Could not open output stream');
            }

            $totalRows = 0;
            try {
                fputcsv($stream, ['date', 'expense', 'amount', 'category', 'is_split', 'tag', 'card', 'created_at', 'updated_at'], ',', '"', '\\');

                foreach ($stmt as $row) {
                    fputcsv($stream, [
                        $this->csvCell((string) $row['transaction_date']),
                        $this->csvCell((string) $row['expense']),
                        $this->csvCell($this->fmt((string) $row['amount'])),
                        $this->csvCell((string) $row['category']),
                        $this->csvCell(((int) $row['is_split']) === 1 ? 'true' : 'false'),
                        $this->csvCell((string) $row['tag_name']),
                        $this->csvCell($row['card_name'] === null ? '' : (string) $row['card_name']),
                        $this->csvCell((string) $row['created_at']),
                        $this->csvCell((string) $row['updated_at']),
                    ], ',', '"', '\\');
                    $totalRows++;
                }

                $this->completeExportRun($exportRunId, 'completed', $totalRows);
            } catch (\Throwable $e) {
                $this->completeExportRun($exportRunId, 'failed', $totalRows, $this->shortErrorSummary($e->getMessage()));
                throw $e;
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function listDataRuns(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $limit = $this->clampedDataRunsLimit($request->query['limit'] ?? null);

        $sql = <<<'SQL'
SELECT *
FROM (
  SELECT
    ci.id AS run_id,
    CONCAT('import_', ci.id) AS id,
    'import' AS type,
    CASE
      WHEN ci.invalid_rows = 0 THEN 'completed'
      WHEN ci.valid_rows > 0 AND ci.invalid_rows > 0 THEN 'partial'
      ELSE 'failed'
    END AS status,
    ci.created_at,
    ci.source_filename,
    NULL AS date_from,
    NULL AS date_to,
    ci.total_rows,
    ci.valid_rows,
    ci.imported_rows,
    ci.duplicate_rows,
    ci.invalid_rows,
    ci.error_summary
  FROM csv_import_runs ci
  WHERE ci.user_id = :import_user_id
    AND ci.mode = 'commit'

  UNION ALL

  SELECT
    ce.id AS run_id,
    CONCAT('export_', ce.id) AS id,
    'export' AS type,
    ce.status,
    ce.created_at,
    NULL AS source_filename,
    ce.date_from,
    ce.date_to,
    ce.total_rows,
    NULL AS valid_rows,
    NULL AS imported_rows,
    NULL AS duplicate_rows,
    NULL AS invalid_rows,
    ce.error_summary
  FROM csv_export_runs ce
  WHERE ce.user_id = :export_user_id
) runs
ORDER BY created_at DESC, run_id DESC
LIMIT :limit
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':import_user_id', $ctx->userId(), PDO::PARAM_INT);
        $stmt->bindValue(':export_user_id', $ctx->userId(), PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->dataRunItem($row);
        }

        return Response::json(['items' => $items]);
    }

    public function importCsv(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);

        $mode = strtolower(trim((string) ($request->input('mode') ?? '')));
        if (!in_array($mode, ['dry_run', 'commit'], true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'mode', 'message' => 'must be dry_run or commit'],
            ]);
        }

        $file = $request->files['file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'file', 'message' => 'csv file upload is required'],
            ]);
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_file($tmpName)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Uploaded file is missing');
        }

        $maxImportBytes = $this->maxImportBytes();
        $maxImportRows = $this->maxImportRows();
        $maxImportErrors = $this->maxImportErrors();
        $uploadedBytes = (int) ($file['size'] ?? 0);
        if ($uploadedBytes <= 0) {
            $detectedBytes = filesize($tmpName);
            $uploadedBytes = $detectedBytes === false ? 0 : (int) $detectedBytes;
        }

        if ($uploadedBytes > $maxImportBytes) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'CSV file is too large', [
                ['field' => 'file', 'message' => 'must be <= ' . $maxImportBytes . ' bytes'],
            ]);
        }

        $handle = fopen($tmpName, 'r');
        if ($handle === false) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Could not read uploaded file');
        }

        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if (!is_array($header)) {
            fclose($handle);
            throw new HttpException(422, 'VALIDATION_ERROR', 'CSV must include a header row');
        }

        try {
            $index = $this->buildColumnIndex($header);
        } catch (HttpException $e) {
            fclose($handle);
            throw $e;
        }

        $totalRows = 0;
        $validRows = 0;
        $importedRows = 0;
        $duplicateRows = 0;
        $invalidRows = 0;
        $errors = [];
        $errorsTruncated = false;
        $parsedRows = [];

        while (($cols = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $totalRows++;
            $rowNum = $totalRows + 1;

            if ($totalRows > $maxImportRows) {
                fclose($handle);
                throw new HttpException(422, 'VALIDATION_ERROR', 'CSV contains too many rows', [
                    ['field' => 'file', 'message' => 'must contain <= ' . $maxImportRows . ' data rows'],
                ]);
            }

            try {
                $parsed = $this->parseImportRow($cols, $index, $rowNum);
                $validRows++;
                $parsedRows[] = $parsed;
            } catch (HttpException $e) {
                $invalidRows++;
                foreach ($e->details() as $detail) {
                    $errorsTruncated = $this->appendImportError(
                        $errors,
                        $rowNum,
                        (string) $detail['field'],
                        (string) $detail['message'],
                        $maxImportErrors
                    ) || $errorsTruncated;
                }
            } catch (\Throwable) {
                $invalidRows++;
                $errorsTruncated = $this->appendImportError(
                    $errors,
                    $rowNum,
                    'row',
                    'unexpected import error',
                    $maxImportErrors
                ) || $errorsTruncated;
            }
        }

        fclose($handle);

        if ($mode === 'dry_run') {
            $importedRows = 0;
            $duplicateRows = $this->estimateDryRunDuplicates($ctx->userId(), $parsedRows);
        } else {
            $ownsTransaction = !$this->pdo->inTransaction();
            if ($ownsTransaction) {
                $this->pdo->beginTransaction();
            }

            try {
                foreach ($parsedRows as $parsed) {
                    $inserted = $this->commitImportedRow($ctx->userId(), $parsed);
                    if ($inserted) {
                        $importedRows++;
                    } else {
                        $duplicateRows++;
                    }
                }

                if ($ownsTransaction) {
                    $this->pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($ownsTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }

        $status = $this->importStatus($validRows, $invalidRows);
        $message = $this->importMessage($mode, $status, $validRows, $importedRows, $duplicateRows, $invalidRows, $errorsTruncated, $maxImportErrors);
        $this->recordImportRun(
            userId: $ctx->userId(),
            mode: $mode,
            status: $status === 'completed' ? 'completed' : 'failed',
            sourceFilename: (string) ($file['name'] ?? 'upload.csv'),
            totalRows: $totalRows,
            validRows: $validRows,
            importedRows: $importedRows,
            duplicateRows: $duplicateRows,
            invalidRows: $invalidRows,
            errorSummary: $invalidRows > 0 ? $message : null
        );

        return Response::json([
            'status' => $status,
            'message' => $message,
            'mode' => $mode,
            'total_rows' => $totalRows,
            'valid_rows' => $validRows,
            'imported_rows' => $importedRows,
            'duplicate_rows' => $duplicateRows,
            'invalid_rows' => $invalidRows,
            'errors_truncated' => $errorsTruncated,
            'max_returned_errors' => $maxImportErrors,
            'errors' => $errors,
        ]);
    }

    /** @param array<int,string> $header */
    private function buildColumnIndex(array $header): array
    {
        $map = [];
        foreach ($header as $i => $col) {
            $normalized = strtolower(trim((string) $col));
            $map[$normalized] = $i;
        }

        // Support common aliases from exported personal budget sheets.
        // If both "tag" and "tags" exist, prefer "tags" because some tools append
        // a derived "Tag" summary column that is not row-level source data.
        if (array_key_exists('tags', $map)) {
            $map['tag'] = $map['tags'];
        }
        if (!array_key_exists('date', $map) && array_key_exists('transaction_date', $map)) {
            $map['date'] = $map['transaction_date'];
        }

        $required = ['date', 'expense', 'amount', 'category', 'tag'];
        foreach ($required as $col) {
            if (!array_key_exists($col, $map)) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'CSV header missing required column: ' . $col);
            }
        }

        return $map;
    }

    /** @param array<int,string> $cols */
    private function parseImportRow(array $cols, array $index, int $rowNum): array
    {
        $date = $this->getCsvValue($cols, $index, 'date');
        $expense = $this->getCsvValue($cols, $index, 'expense');
        $amount = $this->getCsvValue($cols, $index, 'amount');
        $category = $this->getCsvValue($cols, $index, 'category');
        $tag = $this->getCsvValue($cols, $index, 'tag');
        if ($tag === '' && array_key_exists('tags', $index)) {
            $tag = $this->getCsvValue($cols, $index, 'tags');
        }
        $card = $this->getCsvValue($cols, $index, 'card');
        $isSplitRaw = $this->getCsvValue($cols, $index, 'is_split');

        $date = $this->validatedDate($date, 'date');
        $expense = $this->validatedExpense($expense);
        $amount = $this->validatedMoney($amount, 'amount');
        $category = $this->validatedCategory($category);
        $isSplit = $this->validatedOptionalBoolean($isSplitRaw, 'is_split');

        $tagName = trim($tag);
        if ($tagName === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Row validation failed', [
                ['field' => 'tag', 'message' => 'is required'],
            ]);
        }

        $cardName = trim($card);

        return [
            'date' => $date,
            'expense' => $expense,
            'amount' => $amount,
            'category' => $category,
            'is_split' => $isSplit,
            'tag_name' => $tagName,
            'card_name' => $cardName,
            'row' => $rowNum,
        ];
    }

    private function getCsvValue(array $cols, array $index, string $column): string
    {
        if (!array_key_exists($column, $index)) {
            return '';
        }

        $i = (int) $index[$column];
        return trim((string) ($cols[$i] ?? ''));
    }

    /** @param array<string,mixed> $row */
    private function commitImportedRow(int $userId, array $row): bool
    {
        $tagId = $this->findOrCreateTag($userId, (string) $row['tag_name']);
        $cardId = trim((string) $row['card_name']) === '' ? null : $this->findOrCreateCard($userId, (string) $row['card_name']);

        if ($this->hasDuplicateTransaction(
            userId: $userId,
            date: (string) $row['date'],
            amount: (string) $row['amount'],
            expense: (string) $row['expense'],
            category: (string) $row['category'],
            isSplit: (bool) $row['is_split'],
            tagId: $tagId,
            cardId: $cardId
        )) {
            return false;
        }

        $fingerprint = $this->buildImportFingerprint(
            date: (string) $row['date'],
            amount: (string) $row['amount'],
            expense: (string) $row['expense'],
            category: (string) $row['category'],
            isSplit: (bool) $row['is_split'],
            tagId: $tagId,
            cardId: $cardId
        );

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
  import_fingerprint
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
  :import_fingerprint
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
                ':tag_id' => $tagId,
                ':card_id' => $cardId,
                ':import_fingerprint' => $fingerprint,
            ]);

            return true;
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? '') === '23000') {
                return false;
            }
            throw $e;
        }
    }

    /** @param array<int,array<string,mixed>> $parsedRows */
    private function estimateDryRunDuplicates(int $userId, array $parsedRows): int
    {
        $count = 0;

        foreach ($parsedRows as $parsed) {
            try {
                $tagId = $this->findTagId($userId, (string) $parsed['tag_name']);
                $cardId = trim((string) $parsed['card_name']) === '' ? null : $this->findCardId($userId, (string) $parsed['card_name']);
                if ($tagId === null) {
                    continue;
                }

                if ($this->hasDuplicateTransaction(
                    userId: $userId,
                    date: (string) $parsed['date'],
                    amount: (string) $parsed['amount'],
                    expense: (string) $parsed['expense'],
                    category: (string) $parsed['category'],
                    isSplit: (bool) $parsed['is_split'],
                    tagId: $tagId,
                    cardId: $cardId
                )) {
                    $count++;
                }
            } catch (\Throwable) {
            }
        }

        return $count;
    }

    /** @param array<int,array{row:int,field:string,message:string}> $errors */
    private function appendImportError(array &$errors, int $rowNum, string $field, string $message, int $maxErrors): bool
    {
        if (count($errors) >= $maxErrors) {
            return true;
        }

        $errors[] = [
            'row' => $rowNum,
            'field' => $field,
            'message' => $message,
        ];

        return false;
    }

    private function importStatus(int $validRows, int $invalidRows): string
    {
        if ($invalidRows === 0) {
            return 'completed';
        }

        return $validRows > 0 ? 'partial' : 'failed';
    }

    private function importMessage(
        string $mode,
        string $status,
        int $validRows,
        int $importedRows,
        int $duplicateRows,
        int $invalidRows,
        bool $errorsTruncated,
        int $maxImportErrors
    ): string {
        if ($status === 'completed') {
            $message = $mode === 'dry_run'
                ? sprintf('Validated %d row(s); %d duplicate row(s) would be skipped.', $validRows, $duplicateRows)
                : sprintf('Imported %d row(s); skipped %d duplicate row(s).', $importedRows, $duplicateRows);
        } elseif ($status === 'partial') {
            $message = $mode === 'dry_run'
                ? sprintf('Validated %d row(s), but %d row(s) failed validation.', $validRows, $invalidRows)
                : sprintf('Imported %d row(s), skipped %d duplicate row(s), and %d row(s) failed validation.', $importedRows, $duplicateRows, $invalidRows);
        } else {
            $message = sprintf('Import failed: %d row(s) failed validation.', $invalidRows);
        }

        if ($errorsTruncated) {
            $message .= ' Only the first ' . $maxImportErrors . ' row error(s) were returned.';
        }

        return $message;
    }

    private function maxImportBytes(): int
    {
        return max(1, $this->config->getInt('CSV_IMPORT_MAX_BYTES', self::DEFAULT_MAX_IMPORT_BYTES));
    }

    private function maxImportRows(): int
    {
        return max(1, $this->config->getInt('CSV_IMPORT_MAX_ROWS', self::DEFAULT_MAX_IMPORT_ROWS));
    }

    private function maxImportErrors(): int
    {
        return max(1, $this->config->getInt('CSV_IMPORT_MAX_ERRORS', self::DEFAULT_MAX_IMPORT_ERRORS));
    }

    private function csvCell(string $value): string
    {
        if ($value !== '' && preg_match('/^(?:[=+\-@\t\r]|\s+[=+\-@])/', $value) === 1) {
            return "'" . $value;
        }

        return $value;
    }

    private function recordImportRun(
        int $userId,
        string $mode,
        string $status,
        string $sourceFilename,
        int $totalRows,
        int $validRows,
        int $importedRows,
        int $duplicateRows,
        int $invalidRows,
        ?string $errorSummary
    ): void {
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
            ':error_summary' => $errorSummary,
        ]);
    }

    private function recordExportRun(int $userId, ?string $dateFrom, ?string $dateTo): int
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

    private function completeExportRun(int $exportRunId, string $status, int $totalRows, ?string $errorSummary = null): void
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

    private function clampedDataRunsLimit(mixed $value): int
    {
        if ($value === null || trim((string) $value) === '') {
            return 50;
        }

        return min(100, max(1, (int) $value));
    }

    /** @param array<string,mixed> $row */
    private function dataRunItem(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'type' => (string) $row['type'],
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
            'source_filename' => $row['source_filename'] !== null ? (string) $row['source_filename'] : null,
            'date_from' => $row['date_from'] !== null ? (string) $row['date_from'] : null,
            'date_to' => $row['date_to'] !== null ? (string) $row['date_to'] : null,
            'total_rows' => (int) $row['total_rows'],
            'valid_rows' => $row['valid_rows'] !== null ? (int) $row['valid_rows'] : null,
            'imported_rows' => $row['imported_rows'] !== null ? (int) $row['imported_rows'] : null,
            'duplicate_rows' => $row['duplicate_rows'] !== null ? (int) $row['duplicate_rows'] : null,
            'invalid_rows' => $row['invalid_rows'] !== null ? (int) $row['invalid_rows'] : null,
            'error_summary' => $row['error_summary'] !== null ? (string) $row['error_summary'] : null,
        ];
    }

    private function shortErrorSummary(string $message): string
    {
        $summary = trim($message);
        if ($summary === '') {
            return 'Export failed';
        }

        return mb_substr($summary, 0, 1000);
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

    private function hasDuplicateTransaction(
        int $userId,
        string $date,
        string $amount,
        string $expense,
        string $category,
        bool $isSplit,
        int $tagId,
        ?int $cardId
    ): bool {
        $sql = <<<'SQL'
SELECT id
FROM transactions
WHERE user_id = :user_id
  AND deleted_at IS NULL
  AND transaction_date = :transaction_date
  AND amount = :amount
  AND LOWER(TRIM(expense)) = LOWER(TRIM(:expense))
  AND category = :category
  AND is_split = :is_split
  AND tag_id = :tag_id
  AND ((card_id IS NULL AND :card_id_a IS NULL) OR card_id = :card_id_b)
LIMIT 1
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':transaction_date' => $date,
            ':amount' => $amount,
            ':expense' => $expense,
            ':category' => $category,
            ':is_split' => $isSplit ? 1 : 0,
            ':tag_id' => $tagId,
            ':card_id_a' => $cardId,
            ':card_id_b' => $cardId,
        ]);

        return (bool) $stmt->fetch();
    }

    private function findTagId(int $userId, string $name): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM tags WHERE user_id = :user_id AND name = :name AND is_active = 1 AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':name' => $name,
        ]);

        $row = $stmt->fetch();
        return $row ? (int) $row['id'] : null;
    }

    private function findCardId(int $userId, string $name): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM cards WHERE user_id = :user_id AND name = :name AND is_active = 1 AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':name' => $name,
        ]);

        $row = $stmt->fetch();
        return $row ? (int) $row['id'] : null;
    }

    private function findOrCreateTag(int $userId, string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id, is_active, deleted_at FROM tags WHERE user_id = :user_id AND name = :name LIMIT 1');
        $stmt->execute([
            ':user_id' => $userId,
            ':name' => $name,
        ]);
        $row = $stmt->fetch();

        if ($row) {
            if ((int) $row['is_active'] === 0 || $row['deleted_at'] !== null) {
                $reactivate = $this->pdo->prepare(
                    'UPDATE tags SET is_active = 1, deleted_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id'
                );
                $reactivate->execute([
                    ':id' => $row['id'],
                    ':user_id' => $userId,
                ]);
            }

            return (int) $row['id'];
        }

        $insert = $this->pdo->prepare('INSERT INTO tags (user_id, name, is_active) VALUES (:user_id, :name, 1)');
        $insert->execute([
            ':user_id' => $userId,
            ':name' => $name,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function findOrCreateCard(int $userId, string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id, is_active, deleted_at FROM cards WHERE user_id = :user_id AND name = :name LIMIT 1');
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

    /** @param array<string,mixed> $query */
    private function buildFilterWhere(array $query, int $userId, ?array $resolvedDateRange = null): array
    {
        [$dateFrom, $dateTo] = $resolvedDateRange ?? $this->resolveDateRange($query);

        $where = ['t.user_id = :user_id', 't.deleted_at IS NULL'];
        $params = [':user_id' => $userId];

        if ($dateFrom !== null && $dateTo !== null) {
            $where[] = 't.transaction_date BETWEEN :date_from AND :date_to';
            $params[':date_from'] = $dateFrom;
            $params[':date_to'] = $dateTo;
        }

        $categories = $this->parseCategoryCsv((string) ($query['categories'] ?? ''));
        if ($categories !== []) {
            $holders = [];
            foreach ($categories as $i => $cat) {
                $key = ':cat_' . $i;
                $holders[] = $key;
                $params[$key] = $cat;
            }
            $where[] = 't.category IN (' . implode(', ', $holders) . ')';
        }

        $tagIds = $this->parseIdCsv((string) ($query['tag_ids'] ?? ''), 'tag_ids');
        if ($tagIds !== []) {
            $holders = [];
            foreach ($tagIds as $i => $id) {
                $key = ':tag_' . $i;
                $holders[] = $key;
                $params[$key] = $id;
            }
            $where[] = 't.tag_id IN (' . implode(', ', $holders) . ')';
        }

        $cardIds = $this->parseIdCsv((string) ($query['card_ids'] ?? ''), 'card_ids');
        if ($cardIds !== []) {
            $holders = [];
            foreach ($cardIds as $i => $id) {
                $key = ':card_' . $i;
                $holders[] = $key;
                $params[$key] = $id;
            }
            $where[] = 't.card_id IN (' . implode(', ', $holders) . ')';
        }

        $splitFilter = $this->parseSplitFilter($query['is_split'] ?? null, 'is_split');
        if ($splitFilter !== null) {
            $where[] = 't.is_split = :is_split';
            $params[':is_split'] = $splitFilter;
        }

        $searchQuery = $this->validatedSearchQuery($query['q'] ?? null, 'q');
        if ($searchQuery !== null) {
            $where[] = '(LOWER(t.expense) LIKE :search_query OR LOWER(tg.name) LIKE :search_query OR LOWER(COALESCE(c.name, \'\')) LIKE :search_query)';
            $params[':search_query'] = '%' . $searchQuery . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    /** @param array<string,mixed> $query */
    private function resolveDateRange(array $query): array
    {
        $dateFromRaw = trim((string) ($query['date_from'] ?? ''));
        $dateToRaw = trim((string) ($query['date_to'] ?? ''));
        $preset = trim((string) ($query['preset'] ?? ''));

        $hasCustom = ($dateFromRaw !== '' || $dateToRaw !== '');

        if ($hasCustom && $preset !== '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'preset', 'message' => 'cannot be combined with date_from/date_to'],
            ]);
        }

        if ($hasCustom) {
            if ($dateFromRaw === '' || $dateToRaw === '') {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'date_range', 'message' => 'date_from and date_to are both required'],
                ]);
            }

            $dateFrom = $this->validatedDate($dateFromRaw, 'date_from');
            $dateTo = $this->validatedDate($dateToRaw, 'date_to');
            if ($dateFrom > $dateTo) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'date_range', 'message' => 'date_from must be <= date_to'],
                ]);
            }

            return [$dateFrom, $dateTo];
        }

        if ($preset === '') {
            return [null, null];
        }

        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));

        return match ($preset) {
            'last_7_days' => [$today->modify('-6 days')->format('Y-m-d'), $today->format('Y-m-d')],
            'last_30_days' => [$today->modify('-29 days')->format('Y-m-d'), $today->format('Y-m-d')],
            'month_to_date' => [$today->modify('first day of this month')->format('Y-m-d'), $today->format('Y-m-d')],
            'last_month' => [
                $today->modify('first day of last month')->format('Y-m-d'),
                $today->modify('last day of last month')->format('Y-m-d'),
            ],
            'quarter_to_date' => [$this->quarterStart($today)->format('Y-m-d'), $today->format('Y-m-d')],
            default => throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'preset', 'message' => 'unsupported preset'],
            ]),
        };
    }

    private function quarterStart(DateTimeImmutable $date): DateTimeImmutable
    {
        $month = (int) $date->format('n');
        $quarterStartMonth = (int) (floor(($month - 1) / 3) * 3 + 1);
        return $date->setDate((int) $date->format('Y'), $quarterStartMonth, 1);
    }

    private function validatedDate(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be YYYY-MM-DD'],
            ]);
        }

        $raw = trim($value);
        $formats = ['Y-m-d', 'n/j/Y', 'm/d/Y'];

        foreach ($formats as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $raw, new DateTimeZone('UTC'));
            if ($dt && $dt->format($format) === $raw) {
                return $dt->format('Y-m-d');
            }
        }

        throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
            ['field' => $field, 'message' => 'must be a valid date (YYYY-MM-DD or MM/DD/YYYY)'],
        ]);
    }

    private function validatedExpense(mixed $value): string
    {
        $expense = trim((string) $value);
        if ($expense === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Row validation failed', [
                ['field' => 'expense', 'message' => 'is required'],
            ]);
        }

        if (mb_strlen($expense) > 160) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Row validation failed', [
                ['field' => 'expense', 'message' => 'must be <= 160 characters'],
            ]);
        }

        return $expense;
    }

    private function validatedMoney(mixed $value, string $field): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Row validation failed', [
                ['field' => $field, 'message' => 'must be a decimal string like 123.45'],
            ]);
        }

        $raw = trim((string) $value);
        $normalized = str_replace([',', '$', ' '], '', $raw);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $normalized)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Row validation failed', [
                ['field' => $field, 'message' => 'must be a decimal number'],
            ]);
        }

        $numeric = (float) $normalized;
        if ($numeric <= 0.0) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Row validation failed', [
                ['field' => $field, 'message' => 'must be greater than 0'],
            ]);
        }

        return $this->fmt((string) $numeric);
    }

    private function validatedCategory(mixed $value): string
    {
        if (!is_string($value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Row validation failed', [
                ['field' => 'category', 'message' => 'must be one of needs,wants,savings_debts'],
            ]);
        }

        $raw = strtolower(trim($value));
        if (in_array($raw, self::ALLOWED_CATEGORIES, true)) {
            return $raw;
        }

        $normalized = str_replace(['&', '+'], 'and', $raw);
        $normalized = (string) preg_replace('/[^a-z]+/', '_', $normalized);
        $normalized = trim($normalized, '_');

        $aliases = [
            'needs' => 'needs',
            'need' => 'needs',
            'wants' => 'wants',
            'want' => 'wants',
            'savings_debts' => 'savings_debts',
            'savings_and_debts' => 'savings_debts',
            'savings_debt' => 'savings_debts',
            'savings' => 'savings_debts',
            'debt' => 'savings_debts',
            'debts' => 'savings_debts',
        ];

        if (array_key_exists($normalized, $aliases)) {
            return $aliases[$normalized];
        }

        throw new HttpException(422, 'VALIDATION_ERROR', 'Row validation failed', [
            ['field' => 'category', 'message' => 'must be one of needs,wants,savings_debts'],
        ]);
    }

    private function validatedOptionalBoolean(mixed $value, string $field): bool
    {
        if ($value === null) {
            return false;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, ['1', 'true', 'yes', 'y'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'n'], true)) {
            return false;
        }

        throw new HttpException(422, 'VALIDATION_ERROR', 'Row validation failed', [
            ['field' => $field, 'message' => 'must be true/false'],
        ]);
    }

    /** @return list<string> */
    private function parseCategoryCsv(string $csv): array
    {
        $csv = trim($csv);
        if ($csv === '') {
            return [];
        }

        $items = array_values(array_filter(array_map('trim', explode(',', $csv)), static fn(string $v) => $v !== ''));
        foreach ($items as $item) {
            if (!in_array($item, self::ALLOWED_CATEGORIES, true)) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'categories', 'message' => 'contains invalid value: ' . $item],
                ]);
            }
        }

        return $items;
    }

    /** @return list<int> */
    private function parseIdCsv(string $csv, string $field): array
    {
        $csv = trim($csv);
        if ($csv === '') {
            return [];
        }

        $items = array_values(array_filter(array_map('trim', explode(',', $csv)), static fn(string $v) => $v !== ''));
        $ids = [];

        foreach ($items as $item) {
            if (!ctype_digit($item)) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => $field, 'message' => 'must contain numeric IDs'],
                ]);
            }
            $ids[] = (int) $item;
        }

        return $ids;
    }

    private function parseSplitFilter(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '' || $normalized === 'all') {
            return null;
        }

        if ($normalized === '1' || $normalized === 'true' || $normalized === 'split') {
            return 1;
        }

        if ($normalized === '0' || $normalized === 'false' || $normalized === 'not_split') {
            return 0;
        }

        throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
            ['field' => $field, 'message' => 'must be all, split, or not_split'],
        ]);
    }

    private function validatedSearchQuery(mixed $value, string $field): ?string
    {
        $normalized = strtolower(trim((string) ($value ?? '')));
        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) > 120) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be <= 120 characters'],
            ]);
        }

        return $normalized;
    }

    private function fmt(string $decimal): string
    {
        return number_format((float) $decimal, 2, '.', '');
    }
}
