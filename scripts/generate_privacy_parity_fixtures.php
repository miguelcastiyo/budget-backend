<?php

declare(strict_types=1);

require __DIR__ . '/../tests/PrivacyParity/ScenarioCatalog.php';
require __DIR__ . '/../tests/PrivacyParity/ScenarioContext.php';

use PrivacyParity\ScenarioCatalog;
use PrivacyParity\ScenarioContext;

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
$invariantPath = $root . '/docs/financial-domain-invariants.md';
if (!is_file($invariantPath)) {
    fwrite(STDERR, "tracked financial-domain-invariants.md is missing\n");
    exit(1);
}
$highByGroup = [];
foreach (file($invariantPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (preg_match('/^\| (INV-[A-Z0-9-]+) \|.*\| (high) \|/', $line, $match)) {
        foreach (ScenarioCatalog::groupForInvariant($match[1]) as $groupId) $highByGroup[$groupId][] = $match[1];
    }
}
foreach ($highByGroup as $groupId => $ids) {
    $groups[$groupId]['invariants'] = array_values(array_unique(array_merge($groups[$groupId]['invariants'] ?? [], $ids)));
}
$manifest = json_decode((string) file_get_contents($fixtureRoot . '/manifest.json'), true);
$manifestById = [];
foreach (($manifest['entries'] ?? []) as $entry) {
    if (is_array($entry) && isset($entry['fixture_id'])) $manifestById[(string) $entry['fixture_id']] = $entry;
}
$entries = [];
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
    $absoluteSource = $fixtureRoot . '/' . $relative;
    if (!is_file($absoluteSource)) {
        throw new RuntimeException("committed parity fixture is missing: {$fixtureId}");
    }
    $fixture = json_decode((string) file_get_contents($absoluteSource), true);
    if (!is_array($fixture)) {
        throw new RuntimeException("committed parity fixture is invalid: {$fixtureId}");
    }
    $absolute = $outputRoot . '/' . $relative;
    $entries[] = $manifestById[$fixtureId] ?? [
        'fixture_path' => 'tests/fixtures/privacy-parity/' . $relative,
        'fixture_version' => '1.0',
        'fixture_id' => $fixtureId,
        'scenario_id' => $scenario['scenario_id'],
        'group_id' => $groupId,
        'domain' => $scenario['domain'],
        'source_implementation' => 'committed encrypted-domain fixture',
        'future_consumer' => $scenario['future_consumer'],
        'covered_invariant_ids' => $scenario['invariants'],
        'status' => 'verified',
        'source_commit' => 'fixture-corpus',
        'notes' => 'Regenerated from the committed deterministic encrypted-domain fixture corpus; no plaintext implementation is executed.',
        'blocker_code' => '',
        'blocker_owner' => '',
        'required_action' => '',
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
