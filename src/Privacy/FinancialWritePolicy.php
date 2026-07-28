<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Http\HttpException;

final class FinancialWritePolicy
{
    public function __construct(private readonly FinancialPrivacyStateService $states)
    {
    }

    public function requirePlaintextWriteAllowed(int $userId): void
    {
        $state = $this->states->get($userId);
        if ($state !== FinancialPrivacyState::LEGACY_PLAINTEXT && $state !== FinancialPrivacyState::MIGRATION_FAILED) {
            throw new HttpException(409, 'PRIVACY_STATE_CONFLICT', 'Financial writes are unavailable in the current privacy state');
        }
    }
}
