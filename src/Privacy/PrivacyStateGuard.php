<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Http\HttpException;

final class PrivacyStateGuard
{
    public function __construct(private readonly FinancialPrivacyStateService $states)
    {
    }

    /** @param list<FinancialPrivacyState> $allowed */
    public function requireState(int $userId, array $allowed): FinancialPrivacyState
    {
        $state = $this->states->get($userId);
        foreach ($allowed as $candidate) {
            if ($candidate === $state) {
                return $state;
            }
        }

        throw new HttpException(409, 'PRIVACY_STATE_CONFLICT', 'This operation is not available in the current financial privacy state');
    }

}
