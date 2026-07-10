<?php

declare(strict_types=1);

namespace App\Funds;

use App\Http\HttpException;
use PDO;

final class FundCloseoutIntegrationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly FundRepository $repository
    ) {
    }

    /** @param list<array<string,mixed>> $allocations */
    public function validateFundAllocations(int $userId, string $resultType, array $allocations): void
    {
        foreach ($allocations as $index => $allocation) {
            $type = (string) $allocation['allocation_type'];
            $fundId = $allocation['fund_id'] ?? null;

            if ($type === 'fund') {
                if ($resultType !== 'surplus') {
                    throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                        ['field' => 'allocations.' . $index . '.allocation_type', 'message' => 'fund allocations are only allowed for surplus closeouts'],
                    ]);
                }
                if (!is_string($fundId) || trim($fundId) === '') {
                    throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                        ['field' => 'allocations.' . $index . '.fund_id', 'message' => 'is required for fund allocations'],
                    ]);
                }
                $fund = $this->repository->findFundByPublicId($userId, trim($fundId));
                if ($fund === null) {
                    throw new HttpException(404, 'NOT_FOUND', 'Fund not found');
                }
                if ((string) $fund['status'] !== 'active') {
                    throw new HttpException(409, 'CONFLICT', 'Archived funds cannot receive closeout allocations');
                }
                continue;
            }

            if ($fundId !== null && $fundId !== '') {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Request validation failed', [
                    ['field' => 'allocations.' . $index . '.fund_id', 'message' => 'is only allowed for fund allocations'],
                ]);
            }
        }
    }

    /** @param list<array<string,mixed>> $allocations
     *  @return list<array<string,mixed>>
     */
    public function prepareAllocationsForPersistence(int $userId, array $allocations): array
    {
        $normalized = [];
        foreach ($allocations as $allocation) {
            $normalizedAllocation = $allocation;
            if (($allocation['allocation_type'] ?? null) === 'fund') {
                $fund = $this->repository->findFundByPublicId($userId, (string) $allocation['fund_id']);
                if ($fund === null) {
                    throw new HttpException(404, 'NOT_FOUND', 'Fund not found');
                }
                $normalizedAllocation['fund_id'] = (int) $fund['id'];
            }
            $normalized[] = $normalizedAllocation;
        }

        return $normalized;
    }

    public function replaceCloseoutLinkedEntries(int $userId, int $closeoutDbId, string $month, string $voidReason): void
    {
        $this->repository->voidActiveEntriesForCloseout($userId, $closeoutDbId, $voidReason, $this->nowUtc());
    }

    public function createEntriesForCloseout(int $userId, int $closeoutDbId, string $month): void
    {
        $entryDate = $this->lastDayOfMonth($month);
        foreach ($this->repository->activeFundAllocationsForCloseout($closeoutDbId) as $allocation) {
            $this->repository->insertEntry(
                $userId,
                (int) $allocation['fund_id'],
                $entryDate,
                'contribution',
                'in',
                $this->fmt((string) $allocation['amount']),
                'month_closeout',
                null,
                $closeoutDbId,
                (int) $allocation['id'],
                $allocation['notes'] === null ? null : (string) $allocation['notes']
            );
        }
    }

    private function lastDayOfMonth(string $month): string
    {
        $start = new \DateTimeImmutable($month . '-01', new \DateTimeZone('UTC'));
        return $start->modify('last day of this month')->format('Y-m-d');
    }

    private function nowUtc(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function fmt(string $decimal): string
    {
        return number_format((float) $decimal, 2, '.', '');
    }
}
