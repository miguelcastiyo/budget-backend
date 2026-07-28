<?php

declare(strict_types=1);

require __DIR__ . '/../tests/PrivacyParity/ScenarioCatalog.php';
require __DIR__ . '/../tests/PrivacyParity/FixtureValidator.php';

use PrivacyParity\FixtureValidator;
use PrivacyParity\ScenarioCatalog;

$root = dirname(__DIR__);
$fixtureRoot = $root . '/tests/fixtures/privacy-parity';
$manifestPath = $fixtureRoot . '/manifest.json';
$manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
if (!is_array($manifest)) { fwrite(STDERR, "manifest.json is missing or invalid\n"); exit(1); }
$invariantCandidates = [
    $root . '/docs/financial-domain-invariants.md',
    dirname($root) . '/docs-internal/architecture/privacy-program/financial-domain-invariants.md',
];
$invariantPath = array_values(array_filter($invariantCandidates, 'is_file'))[0] ?? null;
$known = [];
$high = [];
foreach (($invariantPath && is_file($invariantPath) ? file($invariantPath, FILE_IGNORE_NEW_LINES) : []) as $line) {
    if (preg_match('/^\| (INV-[A-Z0-9-]+) \|.*\| (high|medium) \|/', $line, $match)) {
        $known[$match[1]] = true;
        if ($match[2] === 'high') $high[$match[1]] = true;
    }
}
$groups = ScenarioCatalog::groups();
$validator = new FixtureValidator();
$manifestResult = $validator->validateManifest($manifest, $groups);
$errors = [];
foreach ($manifest['entries'] as $entry) {
    $path = $root . '/' . $entry['fixture_path'];
    if (!is_file($path)) { $errors[] = "missing {$entry['fixture_path']}"; continue; }
    $fixture = json_decode((string) file_get_contents($path), true);
    if (!is_array($fixture)) { $errors[] = "invalid JSON {$entry['fixture_path']}"; continue; }
    foreach ($validator->validateFixture($fixture, $known) as $error) $errors[] = $entry['fixture_id'] . ': ' . $error;
    if (($fixture['fixture_id'] ?? '') !== $entry['fixture_id']) $errors[] = $entry['fixture_id'] . ': fixture/manifest ID mismatch';
    if (($entry['status'] ?? '') === 'verified' && (($fixture['expected']['result']['status'] ?? '') !== 'captured')) $errors[] = $entry['fixture_id'] . ': verified fixture lacks captured output';
}
if (!$manifestResult['valid']) $errors[] = 'manifest uniqueness/group validation failed';
$covered = [];
foreach ($manifest['entries'] as $entry) {
    if (in_array($entry['status'] ?? '', ['generated', 'verified'], true)) foreach ($entry['covered_invariant_ids'] as $id) $covered[$id] = true;
}
$uncovered = array_values(array_diff(array_keys($high), array_keys($covered)));
$statusCounts = array_count_values(array_map(static fn(array $e): string => (string) ($e['status'] ?? ''), $manifest['entries']));
fwrite(STDOUT, sprintf("Groups: %d/%d\nScenarios: %d\nInvariants: %d total, %d high\nHigh-priority covered: %d/%d\nStatuses: %s\n", count($groups), count($groups), count($manifest['entries']), count($known), count($high), count($high) - count($uncovered), count($high), json_encode($statusCounts)));
if ($uncovered) fwrite(STDOUT, 'Uncovered high-priority invariants: ' . implode(', ', $uncovered) . "\n");
foreach ($errors as $error) fwrite(STDERR, "ERROR: {$error}\n");
exit($errors ? 1 : 0);
