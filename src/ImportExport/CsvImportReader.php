<?php

declare(strict_types=1);

namespace App\ImportExport;

use App\Core\Config;
use App\Http\HttpException;

final class CsvImportReader
{
    private const CATEGORY_PROFILE_MAX_VALUES = 100;
    private const DEFAULT_MAX_IMPORT_BYTES = 5242880;
    private const DEFAULT_MAX_IMPORT_ROWS = 5000;
    private const DEFAULT_MAX_IMPORT_ERRORS = 100;
    private const IMPORT_PREVIEW_SAMPLE_ROWS = 5;

    public function __construct(
        private readonly Config $config,
        private readonly CsvImportMapper $mapper
    ) {
    }

    public function maxImportBytes(): int
    {
        return max(1, $this->config->getInt('CSV_IMPORT_MAX_BYTES', self::DEFAULT_MAX_IMPORT_BYTES));
    }

    public function maxImportRows(): int
    {
        return max(1, $this->config->getInt('CSV_IMPORT_MAX_ROWS', self::DEFAULT_MAX_IMPORT_ROWS));
    }

    public function maxImportErrors(): int
    {
        return max(1, $this->config->getInt('CSV_IMPORT_MAX_ERRORS', self::DEFAULT_MAX_IMPORT_ERRORS));
    }

    public function readUploadedFile(array $file, bool $includeSamples): array
    {
        if ((int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'file', 'message' => 'csv file upload is required'],
            ]);
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_file($tmpName)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Uploaded file is missing');
        }

        $maxImportBytes = $this->maxImportBytes();
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

        try {
            return $this->readImportCsv($handle, $this->maxImportRows(), $includeSamples);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    public function readImportCsv($handle, int $maxImportRows, bool $includeSamples): array
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
                if ($this->mapper->parseFullImportDate($value) !== null) {
                    $fullDateCount++;
                    continue;
                }
                if ($this->mapper->parseYearlessImportDate($value, 2026) !== null) {
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
}
