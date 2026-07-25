<?php

declare(strict_types=1);

namespace PrivacyParity;

final class FixtureValidator
{
    /** @return array<int, string> */
    public function validateFixture(array $fixture, array $knownInvariants, array $supportedVersions = ['1.0']): array
    {
        $required = ['fixture_version', 'fixture_id', 'scenario_id', 'domain', 'description', 'clock', 'input', 'expected', 'normalization', 'invariants'];
        $errors = [];
        foreach ($required as $field) {
            if (!array_key_exists($field, $fixture)) $errors[] = "missing {$field}";
        }
        if (isset($fixture['fixture_version']) && !in_array((string) $fixture['fixture_version'], $supportedVersions, true)) $errors[] = 'unsupported fixture_version';
        if (isset($fixture['invariants']) && is_array($fixture['invariants'])) {
            foreach ($fixture['invariants'] as $id) if (!isset($knownInvariants[$id])) $errors[] = "unknown invariant {$id}";
        }
        if (!isset($fixture['clock']['now'], $fixture['clock']['timezone'], $fixture['clock']['month'])) $errors[] = 'clock must include now, timezone, month';
        if (!isset($fixture['expected']['result'], $fixture['expected']['state'])) $errors[] = 'expected must include result and state';
        return $errors;
    }

    /** @return array<string, bool> */
    public function validateManifest(array $manifest, array $groups): array
    {
        $seen = [];
        $result = ['valid' => true, 'duplicate_fixture' => false, 'duplicate_scenario' => false, 'missing_group' => false];
        foreach (($manifest['entries'] ?? []) as $entry) {
            if (isset($seen[$entry['fixture_id'] ?? ''])) { $result['duplicate_fixture'] = true; $result['valid'] = false; }
            if (isset($seen['scenario:' . ($entry['scenario_id'] ?? '')])) { $result['duplicate_scenario'] = true; $result['valid'] = false; }
            $seen[$entry['fixture_id'] ?? ''] = true;
            $seen['scenario:' . ($entry['scenario_id'] ?? '')] = true;
        }
        foreach (array_keys($groups) as $id) {
            $found = false;
            foreach (($manifest['entries'] ?? []) as $entry) if (($entry['group_id'] ?? '') === $id) $found = true;
            if (!$found) { $result['missing_group'] = true; $result['valid'] = false; }
        }
        return $result;
    }
}
