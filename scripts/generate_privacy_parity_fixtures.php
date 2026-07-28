<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../tests/PrivacyParity/ScenarioCatalog.php';
require __DIR__ . '/../tests/PrivacyParity/ScenarioContext.php';
require __DIR__ . '/../tests/PrivacyParity/FixtureNormalizer.php';
require __DIR__ . '/../tests/PrivacyParity/SymbolicIdMap.php';
require __DIR__ . '/../tests/PrivacyParity/StateSnapshot.php';
require __DIR__ . '/../tests/PrivacyParity/CurrentImplementationAdapter.php';

use PrivacyParity\CurrentImplementationAdapter;
use PrivacyParity\FixtureNormalizer;
use PrivacyParity\ScenarioCatalog;
use PrivacyParity\ScenarioContext;
use PrivacyParity\StateSnapshot;
use PrivacyParity\SymbolicIdMap;

$root = dirname(__DIR__);
$fixtureRoot = $root . '/tests/fixtures/privacy-parity';
$args = $argv;
$write = in_array('--write', $args, true);
$updateVerified = in_array('--update-verified', $args, true);
$groupFilter = null;
$scenarioFilter = null;
$outputRoot = $root . '/tests/fixtures/privacy-parity';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--group=')) $groupFilter = substr($arg, 8);
    if (str_starts_with($arg, '--scenario=')) $scenarioFilter = substr($arg, 11);
    if (str_starts_with($arg, '--output-root=')) $outputRoot = rtrim(substr($arg, 14), '/');
}

if (!$write) {
    fwrite(STDOUT, "Dry run only. Use --write to generate the corpus.\n");
}
if ($write) ScenarioContext::assertSafe($root, $outputRoot);

$groups = ScenarioCatalog::groups();
$invariantCandidates = [
    $root . '/docs/financial-domain-invariants.md',
    dirname($root) . '/docs-internal/architecture/privacy-program/financial-domain-invariants.md',
];
$invariantPath = array_values(array_filter($invariantCandidates, 'is_file'))[0] ?? null;
$highByGroup = [];
foreach (($invariantPath && is_file($invariantPath) ? file($invariantPath, FILE_IGNORE_NEW_LINES) : []) as $line) {
    if (preg_match('/^\| (INV-[A-Z0-9-]+) \|.*\| (high) \|/', $line, $match)) {
        foreach (ScenarioCatalog::groupForInvariant($match[1]) as $groupId) $highByGroup[$groupId][] = $match[1];
    }
}
foreach ($highByGroup as $groupId => $ids) {
    $groups[$groupId]['invariants'] = array_values(array_unique(array_merge($groups[$groupId]['invariants'] ?? [], $ids)));
}
$normalizer = new FixtureNormalizer();
$adapter = new CurrentImplementationAdapter();
$ignoredFields = ['request_id', 'audit_id', 'created_at', 'updated_at', 'archived_at', 'reopened_at', 'path'];
$entries = [];
$sourceCommit = trim((string) shell_exec('git rev-parse HEAD 2>/dev/null'));
foreach ($groups as $groupId => $scenario) {
    if ($groupFilter !== null && $groupId !== $groupFilter) continue;
    $variantInvariantIds = [];
    foreach (($scenario['scenario_variants'] ?? []) as $variant) {
        $variantInvariantIds = array_merge($variantInvariantIds, $variant['invariants'] ?? []);
    }
    $variants = [['scenario_id' => $scenario['scenario_id'], 'description' => $scenario['description'], 'invariants' => array_values(array_diff($scenario['invariants'] ?? [], $variantInvariantIds)), 'case' => 1]];
    foreach (($scenario['scenario_variants'] ?? []) as $index => $variant) {
        $variants[] = [
            'scenario_id' => (string) $variant['scenario_id'],
            'description' => (string) ($variant['description'] ?? $scenario['description']),
            'invariants' => $variant['invariants'] ?? [],
            'case' => $index + 2,
        ];
    }
    foreach ($variants as $variant) {
    if ($scenarioFilter !== null && $variant['scenario_id'] !== $scenarioFilter) continue;
    $scenario['scenario_id'] = $variant['scenario_id'];
    $scenario['description'] = $variant['description'];
    $scenario['invariants'] = array_values(array_unique($variant['invariants'] ?? $scenario['invariants'] ?? []));
    $scenario['input']['steps'][0]['action'] = $variant['scenario_id'];
    $fixtureId = $groupId . '-case-' . str_pad((string) $variant['case'], 3, '0', STR_PAD_LEFT);
    $folder = $scenario['domain'] === 'savings-plan' ? 'savings-plan' : $scenario['domain'];
    $relative = 'v1/' . $folder . '/' . $fixtureId . '.json';
    $capture = $adapter->isBound($groupId) ? $adapter->capture($scenario) : $adapter->blocked($scenario);
    $state = $capture['state'] ?? StateSnapshot::relevant([]);
    unset($capture['state']);
    if ($adapter->isBound($groupId)) {
        foreach (($capture['invariant_checks'] ?? []) as $check => $passed) {
            if ($passed !== true) throw new RuntimeException("{$groupId}: invariant check failed: {$check}");
        }
    }
    $fixture = [
        'fixture_version' => '1.0',
        'fixture_id' => $fixtureId,
        'scenario_id' => $scenario['scenario_id'],
        'domain' => $scenario['domain'],
        'description' => $scenario['description'],
        'clock' => $scenario['clock'],
        'input' => $scenario['input'],
        'expected' => [
            'result' => $capture,
            'state' => $state,
        ],
        'normalization' => [
            'money' => 'decimal_string',
            'dates' => 'iso_date',
            'datetimes' => 'iso_utc_datetime',
            'ids' => 'symbolic_fixture_ids',
            'ordering' => 'declared_stable_order',
            'ignored_fields' => $ignoredFields,
        ],
        'invariants' => $scenario['invariants'],
    ];
    $fixture = $normalizer->normalize((new SymbolicIdMap())->normalize($fixture), $ignoredFields);
    $absolute = $outputRoot . '/' . $relative;
    $entries[] = [
        'fixture_path' => 'tests/fixtures/privacy-parity/' . $relative,
        'fixture_version' => '1.0',
        'fixture_id' => $fixtureId,
        'scenario_id' => $scenario['scenario_id'],
        'group_id' => $groupId,
        'domain' => $scenario['domain'],
        'source_implementation' => $scenario['source'],
        'future_consumer' => $scenario['future_consumer'],
        'covered_invariant_ids' => $scenario['invariants'],
        'status' => $adapter->isBound($groupId) ? 'verified' : 'blocked',
        'source_commit' => $sourceCommit !== '' ? $sourceCommit : 'uncommitted',
        'notes' => $adapter->isBound($groupId)
            ? 'Generated from current authoritative PHP controller/service execution against the isolated parity database.'
            : 'Blocked until a test-only adapter invokes the current authoritative implementation; no financial output was hand-authored.',
        'blocker_code' => $adapter->isBound($groupId) ? '' : 'current_implementation_adapter_not_bound',
        'blocker_owner' => $adapter->isBound($groupId) ? '' : 'Phase 0D execution remediation',
        'required_action' => $adapter->isBound($groupId) ? '' : 'Provision isolated MariaDB and bind the domain adapter for this fixture group',
    ];
    if ($write) {
        if (is_file($absolute) && !$updateVerified) {
            $old = json_decode((string) file_get_contents($absolute), true);
            if (($old['expected']['result']['status'] ?? null) === 'verified') {
                throw new RuntimeException("Refusing to overwrite verified fixture {$fixtureId}; use --update-verified");
            }
        }
        if (!is_dir(dirname($absolute))) mkdir(dirname($absolute), 0775, true);
        file_put_contents($absolute, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
    }
}

if ($write) {
    $manifestPath = $outputRoot . '/manifest.json';
    $existing = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : [];
    $all = [];
    foreach (($existing['entries'] ?? []) as $entry) $all[$entry['fixture_id']] = $entry;
    foreach ($entries as $entry) $all[$entry['fixture_id']] = $entry;
    uasort($all, static fn(array $a, array $b): int => strcmp((string) $a['fixture_id'], (string) $b['fixture_id']));
    file_put_contents($manifestPath, json_encode(['manifest_version' => '1.0', 'fixture_version' => '1.0', 'entries' => array_values($all)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}
$statusCounts = array_count_values(array_map(static fn(array $entry): string => (string) $entry['status'], $entries));
$verifiedGroups = count(array_unique(array_map(static fn(array $entry): string => (string) $entry['group_id'], array_filter($entries, static fn(array $entry): bool => $entry['status'] === 'verified'))));
$blockedGroups = count(array_unique(array_map(static fn(array $entry): string => (string) $entry['group_id'], array_filter($entries, static fn(array $entry): bool => $entry['status'] === 'blocked'))));
fwrite(STDOUT, sprintf("Prepared %d fixture scenario(s) across %d logical group(s); %d verified scenario(s) in %d group(s), %d blocked scenario(s) in %d group(s).\n", count($entries), count($groups), $statusCounts['verified'] ?? 0, $verifiedGroups, $statusCounts['blocked'] ?? 0, $blockedGroups));
