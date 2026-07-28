<?php

declare(strict_types=1);

namespace App\Privacy;

use InvalidArgumentException;

enum FinancialPrivacyState: string
{
    case VAULT_SETUP_REQUIRED = 'vault_setup_required';
    case LEGACY_PLAINTEXT = 'legacy_plaintext';
    case MIGRATION_IN_PROGRESS = 'migration_in_progress';
    case MIGRATION_FAILED = 'migration_failed';
    case ENCRYPTED = 'encrypted';

    public static function fromDatabase(mixed $value): self
    {
        $state = self::tryFrom((string) $value);
        if ($state === null) {
            throw new InvalidArgumentException('Unknown financial privacy state');
        }

        return $state;
    }
}
