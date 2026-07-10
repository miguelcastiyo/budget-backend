<?php

declare(strict_types=1);

namespace App\Funds;

final class FundBalanceService
{
    public function __construct(private readonly FundRepository $repository)
    {
    }

    /** @param array<string,mixed> $fund */
    public function summarizeFund(int $userId, array $fund): array
    {
        $balance = $this->repository->balanceForFund($userId, (int) $fund['id']);
        return $this->decorateFund($fund, $balance);
    }

    /** @param list<array<string,mixed>> $fundRows */
    public function summarizeFunds(int $userId, array $fundRows): array
    {
        $balancesByFund = $this->repository->balancesByFund($userId);
        $items = [];
        foreach ($fundRows as $fund) {
            $items[] = $this->decorateFund(
                $fund,
                $balancesByFund[(string) $fund['id']] ?? '0.00'
            );
        }

        return $items;
    }

    /** @param array<string,mixed> $fund */
    private function decorateFund(array $fund, string $balance): array
    {
        $goalAmount = $fund['goal_amount'] === null ? null : $this->fmt((string) $fund['goal_amount']);
        $balanceFloat = (float) $balance;
        $goalFloat = $goalAmount === null ? null : (float) $goalAmount;
        $remaining = $goalFloat === null ? null : $this->fmt((string) max($goalFloat - $balanceFloat, 0.0));
        $percentFunded = $goalFloat === null || $goalFloat <= 0.0
            ? null
            : $this->fmt((string) min(round(($balanceFloat / $goalFloat) * 100, 2), 999999.99));

        return [
            'id' => (string) $fund['fund_id'],
            'name' => (string) $fund['name'],
            'fund_type' => (string) $fund['fund_type'],
            'goal_amount' => $goalAmount,
            'target_month' => $fund['target_month'] === null ? null : substr((string) $fund['target_month'], 0, 7),
            'notes' => $fund['notes'] === null ? null : (string) $fund['notes'],
            'status' => (string) $fund['status'],
            'sort_order' => (int) $fund['sort_order'],
            'current_balance' => $this->fmt($balance),
            'remaining_amount' => $remaining,
            'percent_funded' => $percentFunded,
            'is_goal_met' => $goalFloat === null ? false : $balanceFloat >= $goalFloat,
            'created_at' => $this->isoDateTime((string) $fund['created_at']),
            'updated_at' => $this->isoDateTime((string) $fund['updated_at']),
            'archived_at' => $fund['archived_at'] === null ? null : $this->isoDateTime((string) $fund['archived_at']),
        ];
    }

    private function fmt(string $decimal): string
    {
        return number_format((float) $decimal, 2, '.', '');
    }

    private function isoDateTime(string $value): string
    {
        $dt = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
