<?php

declare(strict_types=1);

require __DIR__ . '/../tests/PrivacyParity/SymbolicIdMap.php';
require __DIR__ . '/../tests/PrivacyParity/FixtureNormalizer.php';
require __DIR__ . '/../tests/PrivacyParity/FixtureValidator.php';
require __DIR__ . '/../tests/PrivacyParity/ScenarioCatalog.php';

use PrivacyParity\FixtureNormalizer;
use PrivacyParity\FixtureValidator;
use PrivacyParity\ScenarioCatalog;
use PrivacyParity\SymbolicIdMap;

$fail = static function (string $message): never { throw new RuntimeException($message); };
$map = new SymbolicIdMap();
if ($map->normalize('123') !== 'record_1' || $map->normalize('123') !== 'record_1') $fail('symbolic ID mapping is unstable');
$normalizer = new FixtureNormalizer();
if ($normalizer->normalize(12.5) !== '12.50') $fail('money normalization failed');
if ($normalizer->normalize(['keep' => 1, 'request_id' => 'x'], ['request_id']) !== ['keep' => 1]) $fail('ignored-field normalization failed');
if ($normalizer->stableList(['b', 'a']) !== ['a', 'b']) $fail('stable ordering failed');
$groups = ScenarioCatalog::groups();
if (count($groups) !== 24) $fail('fixture group catalog is incomplete');
$fixtureRoot = dirname(__DIR__) . '/tests/fixtures/privacy-parity';
$manifest = json_decode((string) file_get_contents($fixtureRoot . '/manifest.json'), true);
if (!is_array($manifest) || count($manifest['entries'] ?? []) !== 25) $fail('manifest does not contain 25 scenarios');
$known = [];
$invariantPath = dirname(__DIR__) . '/docs/financial-domain-invariants.md';
if (!is_file($invariantPath)) $fail('tracked financial-domain-invariants.md is missing');
foreach (file($invariantPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (preg_match('/^\| (INV-[A-Z0-9-]+) \|/', $line, $match)) $known[$match[1]] = true;
}
$validator = new FixtureValidator();
foreach ($manifest['entries'] as $entry) {
    $fixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/' . $entry['fixture_path']), true);
    if (!is_array($fixture)) $fail('fixture JSON does not parse: ' . $entry['fixture_id']);
    if ($validator->validateFixture($fixture, $known) !== []) $fail('fixture schema contract failed: ' . $entry['fixture_id']);
}
$safetyScripts = [__DIR__ . '/setup_privacy_parity_database.php', __DIR__ . '/reset_privacy_parity_database.php', __DIR__ . '/verify_privacy_parity_database.php'];
foreach ($safetyScripts as $script) {
    $output = [];
    $exit = 0;
    exec('env -u PRIVACY_PARITY_TEST ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $exit);
    if ($exit !== 2) $fail('safety gate did not refuse missing parity flag: ' . basename($script));
}
fwrite(STDOUT, "Privacy parity infrastructure tests passed\n");
