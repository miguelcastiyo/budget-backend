<?php

declare(strict_types=1);

namespace App\MonthCloseout;

use App\Budget\BudgetSettingsResolver;
use App\Core\Config;
use App\Funds\FundCloseoutIntegrationService;
use App\Http\HttpException;
use App\Support\Str;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class MonthCloseoutService
{
    private const SURPLUS_TYPES = ['fund', 'savings', 'investment', 'debt', 'rollover', 'buffer', 'ignored', 'other'];
    private const DEFICIT_TYPES = ['covered_by_buffer', 'savings', 'rollover', 'ignored', 'other'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly BudgetSettingsResolver $budgetSettingsResolver,
        private readonly MonthCloseoutRepository $repository,
        private readonly FundCloseoutIntegrationService $fundCloseoutIntegrationService,
        private readonly ?DateTimeImmutable $clockNow = null
    ) {
    }

    /** @return array<string,mixed> */
    public function getMonthCloseout(int $userId, string $monthString): array
    {
        $month = $this->normalizeMonth($monthString);
        $computed = $this->computeMonthResult($userId, $month);
        $existing = $this->repository->findCloseoutByUserAndMonth($userId, $this->monthStart($month));

        if ($existing !== null) {
            $staleness = $this->stalenessForCloseout($existing, $computed);

            return [
                'month' => $month,
                'status' => (string) $existing['status'],
                'is_closeable' => $computed['is_closeable'],
                'computed' => $computed['computed'],
                'closeout' => $this->serializeCloseout($existing, $staleness['is_stale'], $staleness['stale_reasons']),
            ];
        }

        return [
            'month' => $month,
            'status' => $computed['status'],
            'is_closeable' => $computed['is_closeable'],
            'computed' => $computed['computed'],
            'closeout' => null,
        ];
    }

    /** @return array<string,mixed> */
    public function listMonthCloseouts(int $userId, array $filters): array
    {
        $normalized = $this->normalizeListFilters($filters);
        $rows = $this->repository->listCloseouts($userId, $normalized);

        $items = [];
        foreach ($rows as $row) {
            $month = substr((string) $row['month'], 0, 7);
            $computed = $this->computeMonthResult($userId, $month);
            $staleness = $this->stalenessForCloseout($row, $computed);
            $resultAmount = $this->closeoutResultAmount($row);
            $allocatedAmount = $this->moneyString((float) $row['allocated_amount']);

            $items[] = [
                'id' => (string) $row['closeout_id'],
                'month' => $month,
                'status' => (string) $row['status'],
                'result_type' => (string) $row['result_type'],
                'surplus_amount' => $this->moneyString((float) $row['surplus_amount']),
                'deficit_amount' => $this->moneyString((float) $row['deficit_amount']),
                'allocated_amount' => $allocatedAmount,
                'unallocated_amount' => $this->moneyString(max($resultAmount - (float) $row['allocated_amount'], 0.0)),
                'is_stale' => $staleness['is_stale'],
                'closed_at' => $this->isoDateTime((string) $row['closed_at']),
            ];
        }

        return ['items' => $items];
    }

    /** @param array<string,mixed> $payload */
    public function closeMonth(int $userId, string $monthString, array $payload): array
    {
        $month = $this->normalizeMonth($monthString);
        $computed = $this->computeMonthResult($userId, $month);
        $this->assertMonthCloseable($computed);

        $notes = $this->normalizeNotes($payload['notes'] ?? null);
        $allocations = $this->normalizeAllocations($payload['allocations'] ?? []);
        $resultAmount = $computed['computed']['result_type'] === 'surplus'
            ? $computed['computed']['surplus_amount']
            : $computed['computed']['deficit_amount'];
        $this->validateAllocations($computed['computed']['result_type'], $resultAmount, $allocations);
        $this->fundCloseoutIntegrationService->validateFundAllocations($userId, $computed['computed']['result_type'], $allocations);
        $allocations = $this->fundCloseoutIntegrationService->prepareAllocationsForPersistence($userId, $allocations);

        $existing = $this->repository->findCloseoutByUserAndMonth($userId, $this->monthStart($month));
        $snapshot = $this->snapshotFromComputed($userId, $computed['computed'], $notes);
        $allocationRows = $this->allocationRows($allocations);

        $this->pdo->beginTransaction();
        try {
            if ($existing === null) {
                $closeoutDbId = $this->repository->insertCloseout($snapshot);
            } else {
                $closeoutDbId = (int) $existing['id'];
                $this->fundCloseoutIntegrationService->replaceCloseoutLinkedEntries($userId, $closeoutDbId, $month, 'allocation_replaced');
                $this->repository->updateCloseoutSnapshot($closeoutDbId, $snapshot);
                $this->repository->supersedeAllocationsForCloseout($closeoutDbId);
            }

            if ($allocationRows !== []) {
                $this->repository->insertAllocations($closeoutDbId, $userId, $allocationRows);
            }
            $this->fundCloseoutIntegrationService->createEntriesForCloseout($userId, $closeoutDbId, $month);

            (new \App\Privacy\FinancialRevisionService($this->pdo))->increment($userId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->getMonthCloseout($userId, $month);
    }

    /** @param array<string,mixed> $payload */
    public function updateCloseout(int $userId, string $monthString, array $payload): array
    {
        $month = $this->normalizeMonth($monthString);
        $existing = $this->repository->findCloseoutByUserAndMonth($userId, $this->monthStart($month));
        if ($existing === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Month closeout not found');
        }
        if ((string) $existing['status'] !== 'closed') {
            throw new HttpException(409, 'CONFLICT', 'Only closed month closeouts can be updated');
        }

        $notes = $this->normalizeNotes($payload['notes'] ?? null);
        $allocations = $this->normalizeAllocations($payload['allocations'] ?? []);
        $this->validateAllocations((string) $existing['result_type'], $this->closeoutResultAmount($existing), $allocations);
        $this->fundCloseoutIntegrationService->validateFundAllocations($userId, (string) $existing['result_type'], $allocations);
        $allocations = $this->fundCloseoutIntegrationService->prepareAllocationsForPersistence($userId, $allocations);

        $this->pdo->beginTransaction();
        try {
            $this->repository->updateCloseoutAuthoredFields((int) $existing['id'], $notes);
            $this->fundCloseoutIntegrationService->replaceCloseoutLinkedEntries($userId, (int) $existing['id'], $month, 'allocation_replaced');
            $this->repository->supersedeAllocationsForCloseout((int) $existing['id']);
            $rows = $this->allocationRows($allocations);
            if ($rows !== []) {
                $this->repository->insertAllocations((int) $existing['id'], $userId, $rows);
            }
            $this->fundCloseoutIntegrationService->createEntriesForCloseout($userId, (int) $existing['id'], $month);
            (new \App\Privacy\FinancialRevisionService($this->pdo))->increment($userId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->getMonthCloseout($userId, $month);
    }

    /** @return array<string,mixed> */
    public function reopenMonth(int $userId, string $monthString): array
    {
        $month = $this->normalizeMonth($monthString);
        $existing = $this->repository->findCloseoutByUserAndMonth($userId, $this->monthStart($month));
        if ($existing === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Month closeout not found');
        }

        if ((string) $existing['status'] !== 'reopened') {
            $this->pdo->beginTransaction();
            try {
                $this->repository->markCloseoutReopened((int) $existing['id'], $this->nowUtc());
                $this->fundCloseoutIntegrationService->replaceCloseoutLinkedEntries($userId, (int) $existing['id'], $month, 'closeout_reopened');
                (new \App\Privacy\FinancialRevisionService($this->pdo))->increment($userId);
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }

        return $this->getMonthCloseout($userId, $month);
    }

    /** @return array<string,mixed> */
    public function computeMonthResult(int $userId, string $monthString): array
    {
        $month = $this->normalizeMonth($monthString);
        $relation = $this->monthRelation($month);
        $budgetResolution = $this->budgetSettingsResolver->getEffectiveSettingsForMonth($userId, $month);
        $hasBudget = $budgetResolution['settings'] !== null;

        if (!$hasBudget) {
            return [
                'status' => 'missing_budget',
                'is_closeable' => false,
                'computed' => null,
            ];
        }

        [$monthStart, $monthEnd] = $this->monthDateRange($month);
        $actualByCategory = $this->queryCategoryTotals($userId, $monthStart, $monthEnd);
        $budgetAmounts = $this->budgetSettingsResolver->resolveAmounts($budgetResolution['settings']);
        $income = (float) $budgetResolution['settings']['monthly_income'];

        $plannedNeeds = $budgetAmounts['needs'];
        $plannedWants = $budgetAmounts['wants'];
        $plannedSavings = $budgetAmounts['savings'];
        $plannedTotal = $plannedNeeds + $plannedWants + $plannedSavings;

        $actualNeeds = (float) ($actualByCategory['needs'] ?? 0.0);
        $actualWants = (float) ($actualByCategory['wants'] ?? 0.0);
        $actualSavings = (float) ($actualByCategory['savings'] ?? 0.0);
        $actualTotal = $actualNeeds + $actualWants + $actualSavings;

        $difference = round($plannedTotal - $actualTotal, 2);
        $resultType = 'balanced';
        $surplusAmount = 0.0;
        $deficitAmount = 0.0;
        if ($difference > 0) {
            $resultType = 'surplus';
            $surplusAmount = $difference;
        } elseif ($difference < 0) {
            $resultType = 'deficit';
            $deficitAmount = abs($difference);
        }

        $spendingDifference = round(($plannedNeeds + $plannedWants) - ($actualNeeds + $actualWants), 2);
        $spendingSurplusAmount = $spendingDifference > 0 ? $spendingDifference : 0.0;
        $spendingDeficitAmount = $spendingDifference < 0 ? abs($spendingDifference) : 0.0;

        $computed = [
            'month' => $month,
            'budget_effective_month' => $budgetResolution['resolved_effective_month'],
            'budget_allocation_mode' => (string) $budgetResolution['settings']['allocation_mode'],
            'monthly_income' => $this->moneyString($income),
            'planned' => [
                'needs' => $this->moneyString($plannedNeeds),
                'wants' => $this->moneyString($plannedWants),
                'savings' => $this->moneyString($plannedSavings),
                'total' => $this->moneyString($plannedTotal),
            ],
            'actual' => [
                'needs' => $this->moneyString($actualNeeds),
                'wants' => $this->moneyString($actualWants),
                'savings' => $this->moneyString($actualSavings),
                'total' => $this->moneyString($actualTotal),
            ],
            'result_type' => $resultType,
            'surplus_amount' => $this->moneyString($surplusAmount),
            'deficit_amount' => $this->moneyString($deficitAmount),
            'spending_surplus_amount' => $this->moneyString($spendingSurplusAmount),
            'spending_deficit_amount' => $this->moneyString($spendingDeficitAmount),
        ];
        $computed['calculation_hash'] = $this->buildCalculationHash($computed);

        return [
            'status' => match ($relation) {
                'future' => 'future',
                'current' => 'open',
                default => 'ready_to_close',
            },
            'is_closeable' => $relation === 'past',
            'computed' => $computed,
        ];
    }

    /** @param list<array<string,mixed>> $allocations */
    public function validateAllocations(string $resultType, string|float $resultAmount, array $allocations): void
    {
        $allowedTypes = match ($resultType) {
            'surplus' => self::SURPLUS_TYPES,
            'deficit' => self::DEFICIT_TYPES,
            'balanced' => [],
            default => throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'result_type', 'message' => 'must be a valid closeout result type'],
            ]),
        };

        if ($resultType === 'balanced' && $allocations !== []) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'allocations', 'message' => 'balanced closeouts cannot include allocations'],
            ]);
        }

        $maxCents = $this->asCents($resultAmount);
        $sumCents = 0;
        foreach ($allocations as $index => $allocation) {
            $type = (string) $allocation['allocation_type'];
            if (!in_array($type, $allowedTypes, true)) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'allocations.' . $index . '.allocation_type', 'message' => 'allocation type is not allowed for this closeout result'],
                ]);
            }

            $sumCents += $this->asCents((string) $allocation['amount']);
        }

        if ($sumCents > $maxCents) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Closeout allocations exceed the available result amount', [
                ['field' => 'allocations', 'message' => 'allocated amount cannot exceed ' . $resultType . ' amount'],
            ]);
        }
    }

    public function buildCalculationHash(array $computedResult): string
    {
        $payload = [
            'actual_needs' => $computedResult['actual']['needs'],
            'actual_savings' => $computedResult['actual']['savings'],
            'actual_total' => $computedResult['actual']['total'],
            'actual_wants' => $computedResult['actual']['wants'],
            'budget_allocation_mode' => $computedResult['budget_allocation_mode'],
            'budget_effective_month' => $computedResult['budget_effective_month'],
            'deficit_amount' => $computedResult['deficit_amount'],
            'month' => $computedResult['month'],
            'monthly_income' => $computedResult['monthly_income'],
            'planned_needs' => $computedResult['planned']['needs'],
            'planned_savings' => $computedResult['planned']['savings'],
            'planned_total' => $computedResult['planned']['total'],
            'planned_wants' => $computedResult['planned']['wants'],
            'spending_deficit_amount' => $computedResult['spending_deficit_amount'],
            'spending_surplus_amount' => $computedResult['spending_surplus_amount'],
            'surplus_amount' => $computedResult['surplus_amount'],
        ];
        ksort($payload);

        return Str::hashSha256((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private function assertMonthCloseable(array $computed): void
    {
        if ($computed['is_closeable'] !== true) {
            if ($computed['status'] === 'missing_budget') {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Budget settings are required before closing a month', [
                    ['field' => 'month', 'message' => 'no budget settings could be resolved for this month'],
                ]);
            }

            throw new HttpException(422, 'VALIDATION_ERROR', 'Month cannot be closed yet', [
                ['field' => 'month', 'message' => 'month must be before the current app month'],
            ]);
        }

        if ($computed['computed'] === null) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Budget settings are required before closing a month', [
                ['field' => 'month', 'message' => 'no budget settings could be resolved for this month'],
            ]);
        }
    }

    private function normalizeMonth(string $month, string $field = 'month'): string
    {
        $month = trim($month);
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must use YYYY-MM format'],
            ]);
        }

        // Reset omitted date/time fields so a valid historical month is not
        // rolled into the current month when the current day is out of range.
        $dt = DateTimeImmutable::createFromFormat('!Y-m', $month, new DateTimeZone('UTC'));
        if (!$dt || $dt->format('Y-m') !== $month) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a valid month'],
            ]);
        }

        return $month;
    }

    /** @return array{0:string,1:string} */
    private function monthDateRange(string $month): array
    {
        $start = DateTimeImmutable::createFromFormat('Y-m-d', $month . '-01', new DateTimeZone('UTC'));
        if (!$start) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'month', 'message' => 'must be a valid month'],
            ]);
        }

        return [$start->format('Y-m-d'), $start->modify('last day of this month')->format('Y-m-d')];
    }

    private function monthStart(string $month): string
    {
        return $month . '-01';
    }

    private function monthRelation(string $month): string
    {
        $timezoneName = $this->config->get('APP_TIMEZONE', 'America/Los_Angeles') ?: 'America/Los_Angeles';
        $tz = new DateTimeZone($timezoneName);
        $currentMonth = ($this->clockNow ?? new DateTimeImmutable('now', $tz))->format('Y-m');

        if ($month < $currentMonth) {
            return 'past';
        }
        if ($month > $currentMonth) {
            return 'future';
        }

        return 'current';
    }

    /** @return array<string,float> */
    private function queryCategoryTotals(int $userId, string $monthStart, string $monthEnd): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT category, COALESCE(SUM(amount), 0.00) AS total
             FROM transactions
             WHERE user_id = :user_id
               AND deleted_at IS NULL
               AND transaction_date >= :month_start
               AND transaction_date <= :month_end
             GROUP BY category'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':month_start' => $monthStart,
            ':month_end' => $monthEnd,
        ]);

        $totals = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $totals[(string) $row['category']] = (float) $row['total'];
            }
        }

        return $totals;
    }

    private function normalizeNotes(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'notes', 'message' => 'must be a string or null'],
            ]);
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** @return list<array{allocation_type:string,fund_id:?string,label:?string,amount:string,target_month:?string,notes:?string}> */
    private function normalizeAllocations(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'allocations', 'message' => 'must be an array'],
            ]);
        }

        $allocations = [];
        foreach (array_values($value) as $index => $allocation) {
            if (!is_array($allocation)) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'allocations.' . $index, 'message' => 'must be an object'],
                ]);
            }

            $type = trim((string) ($allocation['allocation_type'] ?? ''));
            if ($type === '') {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'allocations.' . $index . '.allocation_type', 'message' => 'is required'],
                ]);
            }

            $amount = $this->positiveMoneyString($allocation['amount'] ?? null, 'allocations.' . $index . '.amount');
            $label = $this->normalizeOptionalString($allocation['label'] ?? null, 'allocations.' . $index . '.label', 120);
            $notes = $this->normalizeOptionalString($allocation['notes'] ?? null, 'allocations.' . $index . '.notes');
            $fundId = $this->normalizeOptionalString($allocation['fund_id'] ?? null, 'allocations.' . $index . '.fund_id', 64);
            $targetMonth = $allocation['target_month'] ?? null;
            if ($type === 'rollover') {
                if (!is_string($targetMonth) || trim($targetMonth) === '') {
                    throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                        ['field' => 'allocations.' . $index . '.target_month', 'message' => 'is required for rollover allocations'],
                    ]);
                }
                $targetMonth = $this->normalizeMonth($targetMonth, 'allocations.' . $index . '.target_month');
            } elseif ($targetMonth !== null && $targetMonth !== '') {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'allocations.' . $index . '.target_month', 'message' => 'is only allowed for rollover allocations'],
                ]);
            } else {
                $targetMonth = null;
            }

            $allocations[] = [
                'allocation_type' => $type,
                'fund_id' => $fundId,
                'label' => $label,
                'amount' => $amount,
                'target_month' => $targetMonth,
                'notes' => $notes,
            ];
        }

        return $allocations;
    }

    private function normalizeOptionalString(mixed $value, string $field, ?int $maxLength = null): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a string or null'],
            ]);
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if ($maxLength !== null && mb_strlen($trimmed) > $maxLength) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be at most ' . $maxLength . ' characters'],
            ]);
        }

        return $trimmed;
    }

    private function positiveMoneyString(mixed $value, string $field): string
    {
        $normalized = $this->moneyInputString($value, $field);
        if ($this->asCents($normalized) <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be greater than 0.00'],
            ]);
        }

        return $normalized;
    }

    private function moneyInputString(mixed $value, string $field): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a decimal amount'],
            ]);
        }

        $string = trim((string) $value);
        if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $string)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => $field, 'message' => 'must be a decimal amount with at most 2 decimal places'],
            ]);
        }

        return number_format((float) $string, 2, '.', '');
    }

    private function asCents(string|float $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private function moneyString(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    private function nowUtc(): string
    {
        return ($this->clockNow ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /** @return array<string,mixed> */
    private function snapshotFromComputed(int $userId, array $computed, ?string $notes): array
    {
        return [
            ':closeout_id' => Str::randomId('clo'),
            ':user_id' => $userId,
            ':month' => $this->monthStart($computed['month']),
            ':status' => 'closed',
            ':result_type' => $computed['result_type'],
            ':budget_effective_month' => $computed['budget_effective_month'] === null ? null : $this->monthStart((string) $computed['budget_effective_month']),
            ':budget_allocation_mode' => $computed['budget_allocation_mode'],
            ':monthly_income_snapshot' => $computed['monthly_income'],
            ':planned_needs' => $computed['planned']['needs'],
            ':planned_wants' => $computed['planned']['wants'],
            ':planned_savings' => $computed['planned']['savings'],
            ':planned_total' => $computed['planned']['total'],
            ':actual_needs' => $computed['actual']['needs'],
            ':actual_wants' => $computed['actual']['wants'],
            ':actual_savings' => $computed['actual']['savings'],
            ':actual_total' => $computed['actual']['total'],
            ':surplus_amount' => $computed['surplus_amount'],
            ':deficit_amount' => $computed['deficit_amount'],
            ':spending_surplus_amount' => $computed['spending_surplus_amount'],
            ':spending_deficit_amount' => $computed['spending_deficit_amount'],
            ':calculation_hash' => $computed['calculation_hash'],
            ':notes' => $notes,
            ':closed_at' => $this->nowUtc(),
            ':reopened_at' => null,
        ];
    }

    /** @param list<array{allocation_type:string,fund_id:?string,label:?string,amount:string,target_month:?string,notes:?string}> $allocations
     *  @return list<array<string,mixed>>
     */
    private function allocationRows(array $allocations): array
    {
        $rows = [];
        foreach ($allocations as $allocation) {
            $label = $allocation['allocation_type'] === 'fund' ? null : $allocation['label'];
            $rows[] = [
                'allocation_id' => Str::randomId('cla'),
                'allocation_type' => $allocation['allocation_type'],
                'fund_id' => $allocation['fund_id'],
                'label' => $label,
                'amount' => $allocation['amount'],
                'target_month' => $allocation['target_month'] === null ? null : $this->monthStart($allocation['target_month']),
                'notes' => $allocation['notes'],
            ];
        }

        return $rows;
    }

    /** @return array{is_stale:bool,stale_reasons:list<string>} */
    private function stalenessForCloseout(array $closeout, array $computed): array
    {
        if ($computed['computed'] === null) {
            return [
                'is_stale' => true,
                'stale_reasons' => ['calculation_changed'],
            ];
        }

        if ((string) $closeout['calculation_hash'] === (string) $computed['computed']['calculation_hash']) {
            return [
                'is_stale' => false,
                'stale_reasons' => [],
            ];
        }

        return [
            'is_stale' => true,
            'stale_reasons' => ['calculation_changed'],
        ];
    }

    /** @return array<string,mixed> */
    private function serializeCloseout(array $closeout, bool $isStale, array $staleReasons): array
    {
        $allocations = [];
        foreach ($this->repository->listAllocationsForCloseout((int) $closeout['id']) as $allocation) {
            $allocations[] = [
                'id' => (string) $allocation['allocation_id'],
                'allocation_type' => (string) $allocation['allocation_type'],
                'fund_id' => $allocation['fund_public_id'] === null ? null : (string) $allocation['fund_public_id'],
                'fund_name' => $allocation['fund_name'] === null ? null : (string) $allocation['fund_name'],
                'label' => $allocation['label'] === null ? null : (string) $allocation['label'],
                'amount' => $this->moneyString((float) $allocation['amount']),
                'target_month' => $allocation['target_month'] === null ? null : substr((string) $allocation['target_month'], 0, 7),
                'notes' => $allocation['notes'] === null ? null : (string) $allocation['notes'],
            ];
        }

        $resultAmount = $this->closeoutResultAmount($closeout);
        $allocated = (float) $closeout['allocated_amount'];

        return [
            'id' => (string) $closeout['closeout_id'],
            'status' => (string) $closeout['status'],
            'result_type' => (string) $closeout['result_type'],
            'surplus_amount' => $this->moneyString((float) $closeout['surplus_amount']),
            'deficit_amount' => $this->moneyString((float) $closeout['deficit_amount']),
            'allocated_amount' => $this->moneyString($allocated),
            'unallocated_amount' => $this->moneyString(max($resultAmount - $allocated, 0.0)),
            'is_stale' => $isStale,
            'stale_reasons' => $staleReasons,
            'closed_at' => $this->isoDateTime((string) $closeout['closed_at']),
            'reopened_at' => $closeout['reopened_at'] === null ? null : $this->isoDateTime((string) $closeout['reopened_at']),
            'notes' => $closeout['notes'] === null ? null : (string) $closeout['notes'],
            'allocations' => $allocations,
        ];
    }

    private function closeoutResultAmount(array $closeout): float
    {
        return (string) $closeout['result_type'] === 'surplus'
            ? (float) $closeout['surplus_amount']
            : ((string) $closeout['result_type'] === 'deficit' ? (float) $closeout['deficit_amount'] : 0.0);
    }

    private function isoDateTime(string $value): string
    {
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));

        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** @return array<string,mixed> */
    private function normalizeListFilters(array $filters): array
    {
        $normalized = [
            'limit' => 12,
            'date_from' => null,
            'date_to' => null,
            'status' => null,
        ];

        if (($filters['date_from'] ?? null) !== null && $filters['date_from'] !== '') {
            $normalized['date_from'] = $this->monthStart($this->normalizeMonth((string) $filters['date_from'], 'date_from'));
        }
        if (($filters['date_to'] ?? null) !== null && $filters['date_to'] !== '') {
            $normalized['date_to'] = $this->monthStart($this->normalizeMonth((string) $filters['date_to'], 'date_to'));
        }
        if ($normalized['date_from'] !== null && $normalized['date_to'] !== null && $normalized['date_from'] > $normalized['date_to']) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                ['field' => 'date_range', 'message' => 'date_from must be <= date_to'],
            ]);
        }

        if (($filters['status'] ?? null) !== null && $filters['status'] !== '') {
            $status = (string) $filters['status'];
            if (!in_array($status, ['closed', 'reopened'], true)) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'status', 'message' => 'must be closed or reopened'],
                ]);
            }
            $normalized['status'] = $status;
        }

        if (($filters['date_from'] ?? null) === null && ($filters['date_to'] ?? null) === null) {
            $normalized['limit'] = 12;
        } elseif (($filters['limit'] ?? null) !== null) {
            $limit = (int) $filters['limit'];
            $normalized['limit'] = max(1, min($limit, 120));
        } else {
            $normalized['limit'] = 120;
        }

        return $normalized;
    }
}
