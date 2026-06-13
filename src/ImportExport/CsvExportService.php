<?php

declare(strict_types=1);

namespace App\ImportExport;

use App\Http\HttpException;
use App\Http\Response;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class CsvExportService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CsvImportMapper $mapper,
        private readonly DataRunRepository $dataRuns
    ) {
    }

    public function exportCsv(int $userId, array $query): Response
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange($query);
        [$whereSql, $params] = $this->buildFilterWhere($query, $userId, [$dateFrom, $dateTo]);

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

        $exportRunId = $this->dataRuns->recordExportRun($userId, $dateFrom, $dateTo);
        $filename = 'transactions_' . gmdate('Ymd_His') . '.csv';

        return Response::stream(function () use ($stmt, $exportRunId): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                $this->dataRuns->completeExportRun($exportRunId, 'failed', 0, 'Could not open output stream');
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

                $this->dataRuns->completeExportRun($exportRunId, 'completed', $totalRows);
            } catch (\Throwable $e) {
                $this->dataRuns->completeExportRun($exportRunId, 'failed', $totalRows, $this->shortErrorSummary($e->getMessage()));
                throw $e;
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function csvCell(string $value): string
    {
        if ($value !== '' && preg_match('/^(?:[=+\-@\t\r]|\s+[=+\-@])/', $value) === 1) {
            return "'" . $value;
        }

        return $value;
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

        $categories = $this->mapper->parseCategoryCsv((string) ($query['categories'] ?? ''));
        if ($categories !== []) {
            $holders = [];
            foreach ($categories as $i => $cat) {
                $key = ':cat_' . $i;
                $holders[] = $key;
                $params[$key] = $cat;
            }
            $where[] = 't.category IN (' . implode(', ', $holders) . ')';
        }

        $tagIds = $this->mapper->parseIdCsv((string) ($query['tag_ids'] ?? ''), 'tag_ids');
        if ($tagIds !== []) {
            $holders = [];
            foreach ($tagIds as $i => $id) {
                $key = ':tag_' . $i;
                $holders[] = $key;
                $params[$key] = $id;
            }
            $where[] = 't.tag_id IN (' . implode(', ', $holders) . ')';
        }

        $cardIds = $this->mapper->parseIdCsv((string) ($query['card_ids'] ?? ''), 'card_ids');
        if ($cardIds !== []) {
            $holders = [];
            foreach ($cardIds as $i => $id) {
                $key = ':card_' . $i;
                $holders[] = $key;
                $params[$key] = $id;
            }
            $where[] = 't.card_id IN (' . implode(', ', $holders) . ')';
        }

        $splitFilter = $this->mapper->parseSplitFilter($query['is_split'] ?? null, 'is_split');
        if ($splitFilter !== null) {
            $where[] = 't.is_split = :is_split';
            $params[':is_split'] = $splitFilter;
        }

        $searchQuery = $this->mapper->validatedSearchQuery($query['q'] ?? null, 'q');
        if ($searchQuery !== null) {
            $where[] = '(LOWER(t.expense) LIKE :search_expense_query OR LOWER(tg.name) LIKE :search_tag_query OR LOWER(COALESCE(c.name, \'\')) LIKE :search_card_query)';
            $params[':search_expense_query'] = '%' . $searchQuery . '%';
            $params[':search_tag_query'] = '%' . $searchQuery . '%';
            $params[':search_card_query'] = '%' . $searchQuery . '%';
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

            $dateFrom = $this->mapper->validatedDate($dateFromRaw, 'date_from');
            $dateTo = $this->mapper->validatedDate($dateToRaw, 'date_to');
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

    private function shortErrorSummary(string $message): string
    {
        $summary = trim($message);
        if ($summary === '') {
            return 'Export failed';
        }

        return mb_substr($summary, 0, 1000);
    }

    private function fmt(string $decimal): string
    {
        return number_format((float) $decimal, 2, '.', '');
    }
}
