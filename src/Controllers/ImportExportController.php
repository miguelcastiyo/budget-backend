<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Core\Config;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\AuditLogger;
use App\Support\Str;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class ImportExportController
{
    private const ALLOWED_CATEGORIES = ['needs', 'wants', 'savings_debts'];
    private const IMPORT_FIELDS = ['date', 'expense', 'amount', 'category', 'tag', 'card', 'is_split'];
    private const REQUIRED_IMPORT_FIELDS = ['date', 'expense', 'amount', 'category', 'tag'];
    private const CATEGORY_PROFILE_MAX_VALUES = 100;
    private const DEFAULT_MAX_IMPORT_BYTES = 5242880;
    private const DEFAULT_MAX_IMPORT_ROWS = 5000;
    private const DEFAULT_MAX_IMPORT_ERRORS = 100;
    private const IMPORT_PREVIEW_SAMPLE_ROWS = 5;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthService $auth,
        private readonly Config $config,
        private readonly AuditLogger $audit
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

    /** @param array{import_run_id:string} $params */
    public function rollbackImport(Request $request, array $params): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);
        $importRunId = $this->parseEntityId((string) ($params['import_run_id'] ?? ''), 'import_run_id');

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $run = $this->fetchImportRunForRollback($ctx->userId(), $importRunId);
            if ($run['rolled_back_at'] !== null) {
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }

                return Response::json([
                    'status' => 'rolled_back',
                    'import_run_id' => (string) $importRunId,
                    'deleted_rows' => (int) $run['rolled_back_rows'],
                ]);
            }

            $linkedRows = $this->countLinkedImportRows($ctx->userId(), $importRunId, includeDeleted: true);
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
                ':user_id' => $ctx->userId(),
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
                ':user_id' => $ctx->userId(),
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

        $this->audit->record(
            $request,
            $ctx->userId(),
            $ctx->authType,
            'csv_import.rollback',
            'csv_import_run',
            (string) $importRunId,
            ['deleted_rows' => $deletedRows]
        );

        return Response::json([
            'status' => 'rolled_back',
            'import_run_id' => (string) $importRunId,
            'deleted_rows' => $deletedRows,
        ]);
    }

    public function importCsv(Request $request): Response
    {
        $ctx = $this->auth->requireAuth($request, allowApiKey: true, sessionOnly: false);

        $mode = strtolower(trim((string) ($request->input('mode') ?? '')));
        if (!in_array($mode, ['preview', 'dry_run', 'commit'], true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'mode', 'message' => 'must be preview, dry_run, or commit'],
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

        $totalRows = 0;
        $validRows = 0;
        $importedRows = 0;
        $duplicateRows = 0;
        $invalidRows = 0;
        $skippedRows = 0;
        $skippedBlankAmountRows = 0;
        $errors = [];
        $errorsTruncated = false;
        $parsedRows = [];
        $sampleRows = [];

        try {
            $read = $this->readImportCsv($handle, $maxImportRows, $maxImportErrors, $mode === 'preview');
        } finally {
            fclose($handle);
        }

        $header = $read['header'];
        $totalRows = $read['total_rows'];
        $sampleRows = $read['sample_rows'];

        if ($mode === 'preview') {
            return Response::json([
                'mode' => 'preview',
                'headers' => $header,
                'sample_rows' => $sampleRows,
                'column_profiles' => $read['column_profiles'],
                'date_profiles' => $read['date_profiles'],
                'suggested_mapping' => $this->suggestImportMapping($header),
                'total_rows' => $totalRows,
                'limits' => [
                    'max_bytes' => $maxImportBytes,
                    'max_rows' => $maxImportRows,
                    'max_returned_errors' => $maxImportErrors,
                ],
            ]);
        }

        $categoryStrategy = $this->validatedCategoryStrategy($request->input('category_strategy'), $header, $read['column_profiles']);
        $amountStrategy = $this->validatedAmountStrategy($request->input('amount_strategy'));
        $mapping = $this->validatedImportMapping($request->input('mapping'), $header, $categoryStrategy);
        $dateStrategy = $this->validatedDateStrategy($request->input('date_strategy'));
        $tagStrategy = $this->validatedTagStrategy($request->input('tag_strategy'), $ctx->userId());

        foreach ($read['rows'] as $row) {
            $rowNum = (int) $row['row'];
            try {
                $parsed = $this->parseImportRow($row['cols'], $mapping, $categoryStrategy, $amountStrategy, $dateStrategy, $tagStrategy, $rowNum);
                if ($parsed === null) {
                    $skippedRows++;
                    $skippedBlankAmountRows++;
                    continue;
                }
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

        if ($mode === 'dry_run') {
            $importedRows = 0;
            $duplicateRows = $this->estimateDryRunDuplicates($ctx->userId(), $parsedRows);
        } else {
            $ownsTransaction = !$this->pdo->inTransaction();
            if ($ownsTransaction) {
                $this->pdo->beginTransaction();
            }

            try {
                $status = $this->importStatus($validRows, $invalidRows);
                $message = $this->importMessage($mode, $status, $validRows, $importedRows, $duplicateRows, $invalidRows, $skippedRows, $errorsTruncated, $maxImportErrors);
                $importRunId = $this->recordImportRun(
                    userId: $ctx->userId(),
                    mode: $mode,
                    status: $status === 'completed' ? 'completed' : 'failed',
                    sourceFilename: (string) ($file['name'] ?? 'upload.csv'),
                    totalRows: $totalRows,
                    validRows: $validRows,
                    importedRows: 0,
                    duplicateRows: 0,
                    invalidRows: $invalidRows,
                    skippedRows: $skippedRows,
                    skippedBlankAmountRows: $skippedBlankAmountRows,
                    errorSummary: $invalidRows > 0 ? $message : null
                );

                foreach ($parsedRows as $parsed) {
                    $inserted = $this->commitImportedRow($ctx->userId(), $parsed, $importRunId);
                    if ($inserted) {
                        $importedRows++;
                    } else {
                        $duplicateRows++;
                    }
                }

                $status = $this->importStatus($validRows, $invalidRows);
                $message = $this->importMessage($mode, $status, $validRows, $importedRows, $duplicateRows, $invalidRows, $skippedRows, $errorsTruncated, $maxImportErrors);
                $this->completeImportRun(
                    importRunId: $importRunId,
                    userId: $ctx->userId(),
                    status: $status === 'completed' ? 'completed' : 'failed',
                    importedRows: $importedRows,
                    duplicateRows: $duplicateRows,
                    skippedRows: $skippedRows,
                    skippedBlankAmountRows: $skippedBlankAmountRows,
                    errorSummary: $invalidRows > 0 ? $message : null
                );

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
        $message = $this->importMessage($mode, $status, $validRows, $importedRows, $duplicateRows, $invalidRows, $skippedRows, $errorsTruncated, $maxImportErrors);
        if ($mode === 'dry_run') {
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
                skippedRows: $skippedRows,
                skippedBlankAmountRows: $skippedBlankAmountRows,
                errorSummary: $invalidRows > 0 ? $message : null
            );
        }

        return Response::json([
            'status' => $status,
            'message' => $message,
            'mode' => $mode,
            'total_rows' => $totalRows,
            'valid_rows' => $validRows,
            'imported_rows' => $importedRows,
            'duplicate_rows' => $duplicateRows,
            'invalid_rows' => $invalidRows,
            'skipped_rows' => $skippedRows,
            'skipped_blank_amount_rows' => $skippedBlankAmountRows,
            'errors_truncated' => $errorsTruncated,
            'max_returned_errors' => $maxImportErrors,
            'errors' => $errors,
            'new_tags' => $this->plannedNewTags($ctx->userId(), $parsedRows),
            'new_cards' => $this->plannedNewCards($ctx->userId(), $parsedRows),
        ]);
    }

    /** @param resource $handle */
    private function readImportCsv($handle, int $maxImportRows, int $maxImportErrors, bool $includeSamples): array
    {
        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if (!is_array($header)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'CSV must include a header row');
        }

        $header = array_map(static fn($col): string => trim((string) $col), $header);
        $this->assertUsableHeader($header);

        $totalRows = 0;
        $rows = [];
        $sampleRows = [];
        $profileCounts = [];
        $blankCounts = [];
        $profileTruncated = [];
        $dateValues = [];
        foreach ($header as $name) {
            $profileCounts[$name] = [];
            $blankCounts[$name] = 0;
            $profileTruncated[$name] = false;
            $dateValues[$name] = [];
        }

        while (($cols = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $totalRows++;
            if ($totalRows > $maxImportRows) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'CSV contains too many rows', [
                    ['field' => 'file', 'message' => 'must contain <= ' . $maxImportRows . ' data rows'],
                ]);
            }

            $cols = array_map(static fn($col): string => trim((string) $col), $cols);
            $rowNum = $totalRows + 1;
            $rows[] = ['row' => $rowNum, 'cols' => $cols];

            foreach ($header as $i => $name) {
                $value = trim((string) ($cols[$i] ?? ''));
                if ($value === '') {
                    $blankCounts[$name]++;
                    continue;
                }
                $dateValues[$name][] = $value;

                if (array_key_exists($value, $profileCounts[$name])) {
                    $profileCounts[$name][$value]++;
                    continue;
                }

                if (count($profileCounts[$name]) < self::CATEGORY_PROFILE_MAX_VALUES) {
                    $profileCounts[$name][$value] = 1;
                } else {
                    $profileTruncated[$name] = true;
                }
            }

            if ($includeSamples && count($sampleRows) < self::IMPORT_PREVIEW_SAMPLE_ROWS) {
                $sampleRows[] = $this->sampleImportRow($header, $cols);
            }
        }

        return [
            'header' => $header,
            'rows' => $rows,
            'sample_rows' => $sampleRows,
            'column_profiles' => $this->columnProfiles($header, $profileCounts, $blankCounts, $profileTruncated),
            'date_profiles' => $this->dateProfiles($header, $dateValues),
            'total_rows' => $totalRows,
        ];
    }

    private function dateProfiles(array $header, array $dateValues): array
    {
        $profiles = [];
        foreach ($header as $name) {
            $fullDateCount = 0;
            $yearlessDateCount = 0;
            $invalidExamples = [];
            $yearlessExamples = [];

            foreach ($dateValues[$name] as $value) {
                if ($this->parseFullImportDate($value) !== null) {
                    $fullDateCount++;
                    continue;
                }
                if ($this->parseYearlessImportDate($value, 2026) !== null) {
                    $yearlessDateCount++;
                    if (count($yearlessExamples) < 5 && !in_array($value, $yearlessExamples, true)) {
                        $yearlessExamples[] = $value;
                    }
                    continue;
                }
                if (count($invalidExamples) < 5 && !in_array($value, $invalidExamples, true)) {
                    $invalidExamples[] = $value;
                }
            }

            $profiles[] = [
                'header' => $name,
                'full_date_count' => $fullDateCount,
                'yearless_date_count' => $yearlessDateCount,
                'yearless_examples' => $yearlessExamples,
                'invalid_examples' => $invalidExamples,
            ];
        }

        return $profiles;
    }

    private function columnProfiles(array $header, array $profileCounts, array $blankCounts, array $profileTruncated): array
    {
        $profiles = [];
        foreach ($header as $name) {
            $values = [];
            foreach ($profileCounts[$name] as $value => $count) {
                $values[] = [
                    'value' => (string) $value,
                    'count' => (int) $count,
                ];
            }
            usort($values, static fn(array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strcmp((string) $a['value'], (string) $b['value']));

            $profiles[] = [
                'header' => $name,
                'blank_count' => (int) $blankCounts[$name],
                'unique_values_truncated' => (bool) $profileTruncated[$name],
                'unique_values' => $values,
            ];
        }

        return $profiles;
    }

    /** @param array<int,string> $header */
    private function assertUsableHeader(array $header): void
    {
        $seen = [];
        foreach ($header as $i => $col) {
            if ($col === '') {
                throw new HttpException(422, 'VALIDATION_ERROR', 'CSV header contains an empty column name', [
                    ['field' => 'header.' . $i, 'message' => 'must not be empty'],
                ]);
            }
            $key = strtolower($col);
            if (array_key_exists($key, $seen)) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'CSV header contains duplicate column names', [
                    ['field' => 'header.' . $i, 'message' => 'duplicates "' . $col . '"'],
                ]);
            }
            $seen[$key] = true;
        }
    }

    /** @param array<int,string> $header */
    private function sampleImportRow(array $header, array $cols): array
    {
        $row = [];
        foreach ($header as $i => $name) {
            $row[$name] = (string) ($cols[$i] ?? '');
        }

        return $row;
    }

    /** @param array<int,string> $header */
    private function suggestImportMapping(array $header): array
    {
        $normalizedToHeader = [];
        foreach ($header as $col) {
            $normalizedToHeader[$this->normalizedHeader($col)] = $col;
        }

        $aliases = [
            'date' => ['date', 'transaction_date', 'posted_date', 'post_date'],
            'expense' => ['expense', 'description', 'merchant', 'vendor_payee', 'vendor', 'payee', 'raw_statement_text', 'transaction'],
            'amount' => ['amount', 'transaction_amount', 'money_out', 'debit', 'charge', 'cost'],
            'category' => ['category', 'budget_category', 'bank_category_guess', 'type'],
            'tag' => ['tag', 'tags', 'bank_category_guess', 'label'],
            'card' => ['card', 'account', 'payment_source', 'payment_card', 'payment_method'],
            'is_split' => ['is_split', 'split', 'split_transaction'],
        ];

        $mapping = [];
        foreach ($aliases as $field => $candidates) {
            foreach ($candidates as $candidate) {
                if (array_key_exists($candidate, $normalizedToHeader)) {
                    $mapping[$field] = $normalizedToHeader[$candidate];
                    break;
                }
            }
        }

        return $mapping;
    }

    private function normalizedHeader(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = (string) preg_replace('/[^a-z0-9]+/', '_', $normalized);
        return trim($normalized, '_');
    }

    /** @param array<int,string> $header */
    private function validatedImportMapping(mixed $raw, array $header, array $categoryStrategy = ['mode' => 'exact_column']): array
    {
        if ($raw === null || trim((string) $raw) === '') {
            $mapping = $this->suggestImportMapping($header);
        } else {
            $decoded = json_decode((string) $raw, true);
            if (!is_array($decoded)) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'mapping', 'message' => 'must be valid JSON'],
                ]);
            }
            $mapping = $decoded;
        }

        $headerLookup = [];
        foreach ($header as $i => $col) {
            $headerLookup[$col] = $i;
        }

        $details = [];
        foreach (array_keys($mapping) as $field) {
            if (!in_array($field, self::IMPORT_FIELDS, true)) {
                $details[] = ['field' => 'mapping.' . $field, 'message' => 'is not a supported import field'];
            }
        }

        $requiredFields = self::REQUIRED_IMPORT_FIELDS;
        if (($categoryStrategy['mode'] ?? 'exact_column') !== 'exact_column') {
            $requiredFields = array_values(array_filter($requiredFields, static fn(string $field): bool => $field !== 'category'));
        }

        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $mapping) || trim((string) $mapping[$field]) === '') {
                $details[] = ['field' => 'mapping.' . $field, 'message' => 'is required'];
            }
        }

        $usedHeaders = [];
        $resolved = [];
        foreach (self::IMPORT_FIELDS as $field) {
            if (!array_key_exists($field, $mapping) || trim((string) $mapping[$field]) === '') {
                continue;
            }

            $headerName = trim((string) $mapping[$field]);
            if (!array_key_exists($headerName, $headerLookup)) {
                $details[] = ['field' => 'mapping.' . $field, 'message' => 'must reference an existing CSV header'];
                continue;
            }
            if (array_key_exists($headerName, $usedHeaders)) {
                $details[] = ['field' => 'mapping.' . $field, 'message' => 'must not reuse "' . $headerName . '"'];
                continue;
            }

            $usedHeaders[$headerName] = true;
            $resolved[$field] = $headerLookup[$headerName];
        }

        if ($details !== []) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', $details);
        }

        return $resolved;
    }

    /** @param array<int,string> $header */
    private function validatedCategoryStrategy(mixed $raw, array $header, array $columnProfiles = []): array
    {
        if ($raw === null || trim((string) $raw) === '') {
            return ['mode' => 'exact_column'];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'category_strategy', 'message' => 'must be valid JSON'],
            ]);
        }

        $mode = trim((string) ($decoded['mode'] ?? ''));
        if (!in_array($mode, ['exact_column', 'value_map', 'default'], true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'category_strategy.mode', 'message' => 'must be exact_column, value_map, or default'],
            ]);
        }

        if ($mode === 'default') {
            return [
                'mode' => 'default',
                'default_category' => $this->validatedCategory((string) ($decoded['default_category'] ?? '')),
            ];
        }

        if ($mode === 'exact_column') {
            return ['mode' => 'exact_column'];
        }

        $sourceHeader = trim((string) ($decoded['source_header'] ?? ''));
        $headerLookup = array_flip($header);
        if ($sourceHeader === '' || !array_key_exists($sourceHeader, $headerLookup)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'category_strategy.source_header', 'message' => 'must reference an existing CSV header'],
            ]);
        }
        foreach ($columnProfiles as $profile) {
            if (($profile['header'] ?? null) === $sourceHeader && ($profile['unique_values_truncated'] ?? false)) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'category_strategy.source_header', 'message' => 'has too many unique values; choose another source or use a default category'],
                ]);
            }
        }

        $valueMap = $decoded['value_map'] ?? null;
        if (!is_array($valueMap)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'category_strategy.value_map', 'message' => 'must be an object'],
            ]);
        }

        $normalizedMap = [];
        foreach ($valueMap as $sourceValue => $category) {
            $source = trim((string) $sourceValue);
            if ($source === '') {
                continue;
            }
            $normalizedMap[$source] = $this->validatedCategory((string) $category);
        }

        return [
            'mode' => 'value_map',
            'source_header' => $sourceHeader,
            'source_index' => (int) $headerLookup[$sourceHeader],
            'value_map' => $normalizedMap,
        ];
    }

    private function validatedAmountStrategy(mixed $raw): array
    {
        if ($raw === null || trim((string) $raw) === '') {
            return ['blank_mapped_amount' => 'error'];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'amount_strategy', 'message' => 'must be valid JSON'],
            ]);
        }

        $blankMappedAmount = trim((string) ($decoded['blank_mapped_amount'] ?? 'error'));
        if (!in_array($blankMappedAmount, ['error', 'skip'], true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'amount_strategy.blank_mapped_amount', 'message' => 'must be error or skip'],
            ]);
        }

        return ['blank_mapped_amount' => $blankMappedAmount];
    }

    private function validatedDateStrategy(mixed $raw): array
    {
        if ($raw === null || trim((string) $raw) === '') {
            return ['missing_year' => 'reject'];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'date_strategy', 'message' => 'must be valid JSON'],
            ]);
        }

        $missingYear = trim((string) ($decoded['missing_year'] ?? 'reject'));
        if ($missingYear === 'reject') {
            return ['missing_year' => 'reject'];
        }
        if ($missingYear !== 'apply_year') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'date_strategy.missing_year', 'message' => 'must be reject or apply_year'],
            ]);
        }

        $yearRaw = $decoded['year'] ?? null;
        if (!is_int($yearRaw) && !(is_string($yearRaw) && ctype_digit($yearRaw))) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'date_strategy.year', 'message' => 'must be a four-digit year'],
            ]);
        }
        $year = (int) $yearRaw;
        if ($year < 1900 || $year > 2100) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'date_strategy.year', 'message' => 'must be between 1900 and 2100'],
            ]);
        }

        return ['missing_year' => 'apply_year', 'year' => $year];
    }

    private function validatedTagStrategy(mixed $raw, int $userId): array
    {
        if ($raw === null || trim((string) $raw) === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'tag_strategy', 'message' => 'is required'],
            ]);
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'tag_strategy', 'message' => 'must be valid JSON'],
            ]);
        }
        if (($decoded['mode'] ?? null) !== 'value_map' || !is_array($decoded['value_map'] ?? null)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'tag_strategy.value_map', 'message' => 'must map source values to existing or new tags'],
            ]);
        }

        $valueMap = [];
        foreach ($decoded['value_map'] as $sourceValue => $entry) {
            $source = trim((string) $sourceValue);
            if ($source === '') {
                continue;
            }
            if (!is_array($entry)) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'tag_strategy.value_map.' . $source, 'message' => 'must be an object'],
                ]);
            }
            $mode = trim((string) ($entry['mode'] ?? ''));
            if ($mode === 'existing') {
                $tagIdRaw = (string) ($entry['tag_id'] ?? '');
                $tagId = $this->parseEntityId($tagIdRaw, 'tag_strategy.value_map.' . $source . '.tag_id');
                $tagName = $this->tagNameById($userId, $tagId);
                if ($tagName === null) {
                    throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                        ['field' => 'tag_strategy.value_map.' . $source . '.tag_id', 'message' => 'must reference one of your active tags'],
                    ]);
                }
                $valueMap[$source] = ['mode' => 'existing', 'tag_id' => $tagId, 'tag_name' => $tagName];
                continue;
            }
            if ($mode === 'new') {
                $name = trim((string) ($entry['name'] ?? ''));
                if ($name === '') {
                    throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                        ['field' => 'tag_strategy.value_map.' . $source . '.name', 'message' => 'is required'],
                    ]);
                }
                if (mb_strlen($name) > 80) {
                    throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                        ['field' => 'tag_strategy.value_map.' . $source . '.name', 'message' => 'must be <= 80 characters'],
                    ]);
                }
                $valueMap[$source] = ['mode' => 'new', 'tag_id' => null, 'tag_name' => $name];
                continue;
            }

            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'tag_strategy.value_map.' . $source . '.mode', 'message' => 'must be existing or new'],
            ]);
        }

        return ['mode' => 'value_map', 'value_map' => $valueMap];
    }

    /** @param array<int,string> $cols */
    private function parseImportRow(array $cols, array $mapping, array $categoryStrategy, array $amountStrategy, array $dateStrategy, array $tagStrategy, int $rowNum): ?array
    {
        $date = $this->getCsvValue($cols, $mapping, 'date');
        $expense = $this->getCsvValue($cols, $mapping, 'expense');
        $amount = $this->getCsvValue($cols, $mapping, 'amount');
        if ($amount === '' && ($amountStrategy['blank_mapped_amount'] ?? 'error') === 'skip') {
            return null;
        }

        $category = $this->resolvedImportCategory($cols, $mapping, $categoryStrategy);
        $tag = $this->getCsvValue($cols, $mapping, 'tag');
        $card = $this->getCsvValue($cols, $mapping, 'card');
        $isSplitRaw = $this->getCsvValue($cols, $mapping, 'is_split');

        $date = $this->validatedImportDate($date, $dateStrategy);
        $expense = $this->validatedExpense($expense);
        $amount = $this->validatedMoney($amount, 'amount');
        $category = $this->validatedCategory($category);
        $isSplit = $this->validatedOptionalBoolean($isSplitRaw, 'is_split');

        $tag = $this->resolvedImportTag($tag, $tagStrategy);
        if ($tag === null) {
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
            'tag_name' => $tag['tag_name'],
            'tag_id' => $tag['tag_id'],
            'card_name' => $cardName,
            'row' => $rowNum,
        ];
    }

    private function resolvedImportTag(string $raw, array $tagStrategy): ?array
    {
        $source = trim($raw);
        if ($source === '') {
            return null;
        }
        $valueMap = $tagStrategy['value_map'] ?? [];
        if (!array_key_exists($source, $valueMap)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Row validation failed', [
                ['field' => 'tag', 'message' => 'source value "' . $source . '" is not mapped'],
            ]);
        }

        return [
            'tag_id' => $valueMap[$source]['tag_id'],
            'tag_name' => (string) $valueMap[$source]['tag_name'],
        ];
    }

    private function resolvedImportCategory(array $cols, array $mapping, array $categoryStrategy): string
    {
        $mode = (string) ($categoryStrategy['mode'] ?? 'exact_column');
        if ($mode === 'default') {
            return (string) $categoryStrategy['default_category'];
        }
        if ($mode === 'value_map') {
            $source = trim((string) ($cols[(int) $categoryStrategy['source_index']] ?? ''));
            if ($source === '') {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Row validation failed', [
                    ['field' => 'category', 'message' => 'source value is required for category mapping'],
                ]);
            }
            $valueMap = $categoryStrategy['value_map'];
            if (!array_key_exists($source, $valueMap)) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Row validation failed', [
                    ['field' => 'category', 'message' => 'source value "' . $source . '" is not mapped'],
                ]);
            }

            return (string) $valueMap[$source];
        }

        return $this->getCsvValue($cols, $mapping, 'category');
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
    private function commitImportedRow(int $userId, array $row, int $importRunId): bool
    {
        $tagId = $row['tag_id'] !== null ? (int) $row['tag_id'] : $this->findOrCreateTag($userId, (string) $row['tag_name']);
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
                ':tag_id' => $tagId,
                ':card_id' => $cardId,
                ':import_fingerprint' => $fingerprint,
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

    /** @param array<int,array<string,mixed>> $parsedRows */
    private function estimateDryRunDuplicates(int $userId, array $parsedRows): int
    {
        $count = 0;

        foreach ($parsedRows as $parsed) {
            try {
                $tagId = $parsed['tag_id'] !== null ? (int) $parsed['tag_id'] : $this->findTagId($userId, (string) $parsed['tag_name']);
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

    /** @param array<int,array<string,mixed>> $parsedRows */
    private function plannedNewTags(int $userId, array $parsedRows): array
    {
        $items = [];
        $seen = [];

        foreach ($parsedRows as $parsed) {
            $name = trim((string) $parsed['tag_name']);
            if ($name === '') {
                continue;
            }
            if (($parsed['tag_id'] ?? null) !== null) {
                continue;
            }

            $key = mb_strtolower($name);
            if (array_key_exists($key, $seen) || $this->findTagId($userId, $name) !== null) {
                continue;
            }

            $seen[$key] = true;
            $items[] = [
                'name' => $name,
                'icon_key' => $this->inferredTagIconKey($name),
            ];
        }

        return $items;
    }

    /** @param array<int,array<string,mixed>> $parsedRows */
    private function plannedNewCards(int $userId, array $parsedRows): array
    {
        $items = [];
        $seen = [];

        foreach ($parsedRows as $parsed) {
            $name = trim((string) $parsed['card_name']);
            if ($name === '') {
                continue;
            }

            $key = mb_strtolower($name);
            if (array_key_exists($key, $seen) || $this->findCardId($userId, $name) !== null) {
                continue;
            }

            $seen[$key] = true;
            $items[] = ['name' => $name];
        }

        return $items;
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
        int $skippedRows,
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
        if ($skippedRows > 0) {
            $message .= ' Skipped ' . $skippedRows . ' row(s).';
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

    private function completeImportRun(
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

    /** @return array<string,mixed> */
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

    private function parseEntityId(string $raw, string $field): int
    {
        if ($raw === '' || !ctype_digit($raw)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a numeric id'],
            ]);
        }

        return (int) $raw;
    }

    /** @param array<string,mixed> $row */
    private function dataRunItem(array $row): array
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
            'SELECT id FROM tags WHERE user_id = :user_id AND LOWER(name) = LOWER(:name) AND is_active = 1 AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':name' => $name,
        ]);

        $row = $stmt->fetch();
        return $row ? (int) $row['id'] : null;
    }

    private function tagNameById(int $userId, int $tagId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT name FROM tags WHERE id = :id AND user_id = :user_id AND is_active = 1 AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([
            ':id' => $tagId,
            ':user_id' => $userId,
        ]);

        $row = $stmt->fetch();
        return $row ? (string) $row['name'] : null;
    }

    private function findCardId(int $userId, string $name): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM cards WHERE user_id = :user_id AND LOWER(name) = LOWER(:name) AND is_active = 1 AND deleted_at IS NULL LIMIT 1'
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
                    ':icon_key' => $this->inferredTagIconKey($name),
                ]);
            }

            return (int) $row['id'];
        }

        $insert = $this->pdo->prepare('INSERT INTO tags (user_id, name, icon_key, is_active) VALUES (:user_id, :name, :icon_key, 1)');
        $insert->execute([
            ':user_id' => $userId,
            ':name' => $name,
            ':icon_key' => $this->inferredTagIconKey($name),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function inferredTagIconKey(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        $rules = [
            'home' => ['housing', 'rent', 'mortgage', 'home', 'utilities'],
            'shopping_cart' => ['groceries', 'grocery', 'shopping', 'target', 'costco'],
            'car' => ['transportation', 'gas', 'uber', 'lyft', 'car', 'auto'],
            'plane' => ['travel', 'trip', 'flight', 'airbnb', 'hotel'],
            'receipt' => ['eating out', 'restaurant', 'dining', 'food'],
            'coffee' => ['coffee', 'cafe'],
            'smartphone' => ['subscriptions', 'subscription', 'netflix', 'spotify', 'icloud'],
            'credit_card' => ['debt', 'loan', 'credit'],
            'piggy_bank' => ['savings', 'emergency fund'],
            'trending_up' => ['investments', 'invest', 'roth', 'ira', 'brokerage'],
            'briefcase' => ['salary', 'income', 'paycheck', 'work'],
            'heart' => ['health', 'medical', 'doctor', 'pharmacy'],
            'dumbbell' => ['gym', 'fitness', 'workout'],
            'book_open' => ['education', 'book', 'kindle', 'course', 'school'],
            'film' => ['entertainment', 'movies', 'theater', 'amc'],
            'gamepad' => ['fun', 'gaming', 'game'],
            'gift' => ['gift', 'birthday'],
            'shield' => ['insurance'],
            'lightbulb' => ['personal', 'self care', 'beauty'],
            'wrench' => ['maintenance', 'repair', 'tools'],
            'wallet' => ['cash', 'money', 'wallet'],
        ];

        foreach ($rules as $iconKey => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $keyword)) {
                    return $iconKey;
                }
            }
        }

        return 'tag';
    }

    private function findOrCreateCard(int $userId, string $name): int
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

    private function validatedImportDate(mixed $value, array $dateStrategy): string
    {
        if (!is_string($value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Row validation failed', [
                ['field' => 'date', 'message' => 'must be a valid date'],
            ]);
        }

        $raw = trim($value);
        $full = $this->parseFullImportDate($raw);
        if ($full !== null) {
            return $full;
        }

        if (($dateStrategy['missing_year'] ?? 'reject') === 'apply_year') {
            $yearless = $this->parseYearlessImportDate($raw, (int) $dateStrategy['year']);
            if ($yearless !== null) {
                return $yearless;
            }
        }

        if ($this->looksYearlessDate($raw)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Row validation failed', [
                ['field' => 'date', 'message' => 'missing year; choose a year in date setup'],
            ]);
        }

        throw new HttpException(422, 'VALIDATION_ERROR', 'Row validation failed', [
            ['field' => 'date', 'message' => 'must be a valid date'],
        ]);
    }

    private function parseFullImportDate(string $raw): ?string
    {
        $formats = ['Y-m-d', 'n/j/Y', 'm/d/Y'];
        foreach ($formats as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $raw, new DateTimeZone('UTC'));
            if ($dt && $dt->format($format) === $raw) {
                return $dt->format('Y-m-d');
            }
        }

        return null;
    }

    private function parseYearlessImportDate(string $raw, int $year): ?string
    {
        if (!$this->looksYearlessDate($raw)) {
            return null;
        }

        [$monthRaw, $dayRaw] = explode('/', $raw);
        $month = (int) $monthRaw;
        $day = (int) $dayRaw;
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function looksYearlessDate(string $raw): bool
    {
        return preg_match('/^\d{1,2}\/\d{1,2}$/', $raw) === 1;
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
