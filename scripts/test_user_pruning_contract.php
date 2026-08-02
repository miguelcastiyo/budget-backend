<?php

declare(strict_types=1);

$script = file_get_contents(__DIR__ . '/transition/prune_users.php');
if ($script === false) throw new RuntimeException('Could not read user-pruning command');
foreach (["'production' => 1", "'local' => 3", '--dry-run', '--confirm-delete', 'GET_LOCK', 'DELETE FROM users WHERE id <> :id', 'financial_privacy_state', 'encrypted_record_batches', 'monthly_closeouts', 'privacy_safe_scope'] as $marker) {
    if (!str_contains($script, $marker)) throw new RuntimeException("user-pruning safety contract is missing {$marker}");
}
if (str_contains($script, 'TRUNCATE') || str_contains($script, 'DROP TABLE') || str_contains($script, 'SELECT * FROM users')) throw new RuntimeException('user-pruning command contains an unsafe broad operation');
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/transition/prune_users.php') . ' --environment=production --preserve-user-id=3';
exec($command . ' 2>/dev/null', $output, $exitCode);
if ($exitCode === 0) throw new RuntimeException('cross-environment preserved user mapping was accepted');
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/transition/prune_users.php') . ' --environment=unknown --preserve-user-id=1';
exec($command . ' 2>/dev/null', $output, $exitCode);
if ($exitCode === 0) throw new RuntimeException('unknown environment was accepted');
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/transition/prune_users.php') . ' --environment=local --preserve-user-id=3 --dry-run --confirm-delete';
exec($command . ' 2>/dev/null', $output, $exitCode);
if ($exitCode === 0) throw new RuntimeException('combined dry-run and destructive flags were accepted');
echo "User-pruning safety contract passed\n";
