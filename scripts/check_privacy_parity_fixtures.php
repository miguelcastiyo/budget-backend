<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$fixtureRoot = $root . '/tests/fixtures/privacy-parity';
$parityTest = getenv('PRIVACY_PARITY_TEST') === '1';
if (!$parityTest) {
    fwrite(STDERR, "Set PRIVACY_PARITY_TEST=1 and point DB_DSN at the dedicated *_privacy_parity_test MariaDB database\n");
    exit(2);
}
$dsn = getenv('DB_DSN') ?: '';
if (!preg_match('/^mysql:.*dbname=([^;]+)/', $dsn, $dsnMatch) || !preg_match('/_privacy_parity_test$/', $dsnMatch[1])) {
    fwrite(STDERR, "DB_DSN must point to the dedicated *_privacy_parity_test MariaDB database\n");
    exit(2);
}
$manifest = json_decode((string) file_get_contents($fixtureRoot . '/manifest.json'), true);
if (!is_array($manifest)) { fwrite(STDERR, "manifest.json is invalid\n"); exit(1); }
$tmp = $fixtureRoot . '/.determinism-check-' . bin2hex(random_bytes(6));
mkdir($tmp, 0775, true);
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/generate_privacy_parity_fixtures.php') . ' --write --output-root=' . escapeshellarg($tmp);
$env = 'PRIVACY_PARITY_TEST=1';
$output = [];
$exit = 0;
exec($env . ' ' . $command . ' 2>&1', $output, $exit);
if ($exit !== 0) { fwrite(STDERR, implode("\n", $output) . "\n"); exit($exit); }
// Run twice and compare only generated content. The committed corpus remains untouched.
$tmp2 = $fixtureRoot . '/.determinism-check-' . bin2hex(random_bytes(6));
mkdir($tmp2, 0775, true);
$command2 = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/generate_privacy_parity_fixtures.php') . ' --write --output-root=' . escapeshellarg($tmp2);
$output2 = [];
exec($env . ' ' . $command2 . ' 2>&1', $output2, $exit2);
$diff = $exit2 === 0 ? shell_exec('diff -ru ' . escapeshellarg($tmp . '/v1') . ' ' . escapeshellarg($tmp2 . '/v1')) : 'second generation failed';
exec('rm -rf ' . escapeshellarg($tmp) . ' ' . escapeshellarg($tmp2));
if ($exit2 !== 0 || trim((string) $diff) !== '') { fwrite(STDERR, "Parity regeneration is not deterministic\n" . (string) $diff); exit(1); }
fwrite(STDOUT, "Two clean regeneration runs matched byte-for-byte; committed fixtures were not modified.\n");
exit(0);
