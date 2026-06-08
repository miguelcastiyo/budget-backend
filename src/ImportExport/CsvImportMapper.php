<?php

declare(strict_types=1);

namespace App\ImportExport;

use App\Http\HttpException;
use DateTimeImmutable;
use DateTimeZone;

final class CsvImportMapper
{
    public const ALLOWED_CATEGORIES = ['needs', 'wants', 'savings'];
    public const IMPORT_FIELDS = ['date', 'expense', 'amount', 'category', 'tag', 'card', 'is_split'];
    public const REQUIRED_IMPORT_FIELDS = ['date', 'expense', 'amount', 'category', 'tag'];

    public function __construct(private readonly ?TaxonomyImportRepository $taxonomy = null)
    {
    }

    /** @param array<int,string> $header */
    public function suggestImportMapping(array $header): array
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

    public function normalizedHeader(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = (string) preg_replace('/[^a-z0-9]+/', '_', $normalized);
        return trim($normalized, '_');
    }

    /** @param array<int,string> $header */
    public function validatedImportMapping(mixed $raw, array $header, array $categoryStrategy = ['mode' => 'exact_column']): array
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
    public function validatedCategoryStrategy(mixed $raw, array $header, array $columnProfiles = []): array
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

    public function validatedAmountStrategy(mixed $raw): array
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

    public function validatedDateStrategy(mixed $raw): array
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

    public function validatedTagStrategy(mixed $raw, int $userId): array
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

        $existingTagIds = [];
        foreach ($decoded['value_map'] as $entry) {
            if (is_array($entry) && trim((string) ($entry['mode'] ?? '')) === 'existing') {
                $tagIdRaw = (string) ($entry['tag_id'] ?? '');
                if ($tagIdRaw !== '' && ctype_digit($tagIdRaw)) {
                    $existingTagIds[] = (int) $tagIdRaw;
                }
            }
        }
        $activeTagNames = $this->taxonomy?->activeTagNamesByIds($userId, $existingTagIds) ?? [];

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
                $tagName = $activeTagNames[$tagId] ?? null;
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
    public function parseImportRow(array $cols, array $mapping, array $categoryStrategy, array $amountStrategy, array $dateStrategy, array $tagStrategy, int $rowNum): ?array
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

        return [
            'date' => $date,
            'expense' => $expense,
            'amount' => $amount,
            'category' => $category,
            'is_split' => $isSplit,
            'tag_name' => $tag['tag_name'],
            'tag_id' => $tag['tag_id'],
            'card_name' => trim($card),
            'row' => $rowNum,
        ];
    }

    public function appendImportError(array &$errors, int $rowNum, string $field, string $message, int $maxErrors): bool
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

    public function importStatus(int $validRows, int $invalidRows): string
    {
        if ($invalidRows === 0) {
            return 'completed';
        }

        return $validRows > 0 ? 'partial' : 'failed';
    }

    public function importMessage(
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

    public function inferredTagIconKey(string $name): string
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

    public function validatedDate(mixed $value, string $field): string
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

    public function parseCategoryCsv(string $csv): array
    {
        $csv = trim($csv);
        if ($csv === '') {
            return [];
        }

        $items = array_values(array_filter(array_map('trim', explode(',', $csv)), static fn(string $v): bool => $v !== ''));
        foreach ($items as $item) {
            if (!in_array($item, self::ALLOWED_CATEGORIES, true)) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'categories', 'message' => 'contains invalid value: ' . $item],
                ]);
            }
        }

        return $items;
    }

    public function parseIdCsv(string $csv, string $field): array
    {
        $csv = trim($csv);
        if ($csv === '') {
            return [];
        }

        $items = array_values(array_filter(array_map('trim', explode(',', $csv)), static fn(string $v): bool => $v !== ''));
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

    public function parseSplitFilter(mixed $value, string $field): ?int
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

    public function validatedSearchQuery(mixed $value, string $field): ?string
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

    public function parseEntityId(string $raw, string $field): int
    {
        if ($raw === '' || !ctype_digit($raw)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a numeric id'],
            ]);
        }

        return (int) $raw;
    }

    public function parseFullImportDate(string $raw): ?string
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

    public function parseYearlessImportDate(string $raw, int $year): ?string
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
                ['field' => 'category', 'message' => 'must be one of needs,wants,savings'],
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
            'savings' => 'savings',
            'savings_and_debts' => 'savings',
            'savings_debt' => 'savings',
            'debt' => 'needs',
            'debts' => 'needs',
            'loan' => 'needs',
            'loans' => 'needs',
            'credit_card_payment' => 'needs',
            'credit_card_payments' => 'needs',
            'debt_payment' => 'needs',
            'debt_payments' => 'needs',
        ];

        if (array_key_exists($normalized, $aliases)) {
            return $aliases[$normalized];
        }

        throw new HttpException(422, 'VALIDATION_ERROR', 'Row validation failed', [
            ['field' => 'category', 'message' => 'must be one of needs,wants,savings'],
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

    private function fmt(string $decimal): string
    {
        return number_format((float) $decimal, 2, '.', '');
    }
}
