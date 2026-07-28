<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Http\HttpException;

final class FinancialReadPolicy
{
    public function __construct(private readonly FinancialPrivacyStateService $states)
    {
    }

    public function requireLegacyReadAllowed(int $userId): void
    {
        $state = $this->states->get($userId);
        if ($state !== FinancialPrivacyState::LEGACY_PLAINTEXT
            && $state !== FinancialPrivacyState::MIGRATION_IN_PROGRESS
            && $state !== FinancialPrivacyState::MIGRATION_FAILED) {
            throw new HttpException(409, 'PRIVACY_STATE_CONFLICT', 'Legacy financial reads are unavailable after encrypted cutover');
        }
    }
}
