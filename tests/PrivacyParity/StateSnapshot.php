<?php

declare(strict_types=1);

namespace PrivacyParity;

final class StateSnapshot
{
    /** @param array<string, mixed> $state */
    public static function relevant(array $state): array
    {
        return [
            'records' => $state['records'] ?? [],
            'tombstones' => $state['tombstones'] ?? [],
            'voided_entries' => $state['voided_entries'] ?? [],
            'historical_versions' => $state['historical_versions'] ?? [],
            'source_records' => $state['source_records'] ?? [],
        ];
    }
}
