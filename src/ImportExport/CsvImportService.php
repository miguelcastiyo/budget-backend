<?php

declare(strict_types=1);

namespace App\ImportExport;

use App\Http\HttpException;
use PDO;

final class CsvImportService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CsvImportReader $reader,
        private readonly CsvImportMapper $mapper,
        private readonly CsvImportCommitter $committer,
        private readonly DataRunRepository $dataRuns
    ) {
    }

    public function importCsv(int $userId, array $file, array $input): array
    {
        $mode = strtolower(trim((string) ($input['mode'] ?? '')));
        if (!in_array($mode, ['preview', 'dry_run', 'commit'], true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'mode', 'message' => 'must be preview, dry_run, or commit'],
            ]);
        }

        $read = $this->reader->readUploadedFile($file, $mode === 'preview');
        $header = $read['header'];
        $totalRows = (int) $read['total_rows'];

        if ($mode === 'preview') {
            return [
                'mode' => 'preview',
                'headers' => $header,
                'sample_rows' => $read['sample_rows'],
                'column_profiles' => $read['column_profiles'],
                'date_profiles' => $read['date_profiles'],
                'suggested_mapping' => $this->mapper->suggestImportMapping($header),
                'total_rows' => $totalRows,
                'limits' => [
                    'max_bytes' => $this->reader->maxImportBytes(),
                    'max_rows' => $this->reader->maxImportRows(),
                    'max_returned_errors' => $this->reader->maxImportErrors(),
                ],
            ];
        }

        $categoryStrategy = $this->mapper->validatedCategoryStrategy($input['category_strategy'] ?? null, $header, $read['column_profiles']);
        $amountStrategy = $this->mapper->validatedAmountStrategy($input['amount_strategy'] ?? null);
        $mapping = $this->mapper->validatedImportMapping($input['mapping'] ?? null, $header, $categoryStrategy);
        $dateStrategy = $this->mapper->validatedDateStrategy($input['date_strategy'] ?? null);
        $tagStrategy = $this->mapper->validatedTagStrategy($input['tag_strategy'] ?? null, $userId);

        $validRows = 0;
        $importedRows = 0;
        $duplicateRows = 0;
        $invalidRows = 0;
        $skippedRows = 0;
        $skippedBlankAmountRows = 0;
        $errors = [];
        $errorsTruncated = false;
        $parsedRows = [];
        $maxImportErrors = $this->reader->maxImportErrors();

        foreach ($read['rows'] as $row) {
            $rowNum = (int) $row['row'];
            try {
                $parsed = $this->mapper->parseImportRow($row['cols'], $mapping, $categoryStrategy, $amountStrategy, $dateStrategy, $tagStrategy, $rowNum);
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
                    $errorsTruncated = $this->mapper->appendImportError(
                        $errors,
                        $rowNum,
                        (string) $detail['field'],
                        (string) $detail['message'],
                        $maxImportErrors
                    ) || $errorsTruncated;
                }
            } catch (\Throwable) {
                $invalidRows++;
                $errorsTruncated = $this->mapper->appendImportError(
                    $errors,
                    $rowNum,
                    'row',
                    'unexpected import error',
                    $maxImportErrors
                ) || $errorsTruncated;
            }
        }

        if ($mode === 'dry_run') {
            $duplicateRows = $this->committer->estimateDryRunDuplicates($userId, $parsedRows);
        } else {
            $ownsTransaction = !$this->pdo->inTransaction();
            if ($ownsTransaction) {
                $this->pdo->beginTransaction();
            }

            try {
                $status = $this->mapper->importStatus($validRows, $invalidRows);
                $message = $this->mapper->importMessage($mode, $status, $validRows, $importedRows, $duplicateRows, $invalidRows, $skippedRows, $errorsTruncated, $maxImportErrors);
                $importRunId = $this->dataRuns->recordImportRun(
                    userId: $userId,
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

                $commitResult = $this->committer->commitRows($userId, $parsedRows, $importRunId);
                $importedRows = (int) $commitResult['imported_rows'];
                $duplicateRows = (int) $commitResult['duplicate_rows'];

                $status = $this->mapper->importStatus($validRows, $invalidRows);
                $message = $this->mapper->importMessage($mode, $status, $validRows, $importedRows, $duplicateRows, $invalidRows, $skippedRows, $errorsTruncated, $maxImportErrors);
                $this->dataRuns->completeImportRun(
                    importRunId: $importRunId,
                    userId: $userId,
                    status: $status === 'completed' ? 'completed' : 'failed',
                    importedRows: $importedRows,
                    duplicateRows: $duplicateRows,
                    skippedRows: $skippedRows,
                    skippedBlankAmountRows: $skippedBlankAmountRows,
                    errorSummary: $invalidRows > 0 ? $message : null
                );

                if ($importedRows > 0) {
                    (new \App\Privacy\FinancialRevisionService($this->pdo))->increment($userId);
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

        $status = $this->mapper->importStatus($validRows, $invalidRows);
        $message = $this->mapper->importMessage($mode, $status, $validRows, $importedRows, $duplicateRows, $invalidRows, $skippedRows, $errorsTruncated, $maxImportErrors);
        if ($mode === 'dry_run') {
            $this->dataRuns->recordImportRun(
                userId: $userId,
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

        return [
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
            'new_tags' => $this->committer->plannedNewTags($userId, $parsedRows),
            'new_cards' => $this->committer->plannedNewCards($userId, $parsedRows),
        ];
    }
}
