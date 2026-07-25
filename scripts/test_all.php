<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$scripts = [
    'scripts/test_core.php',
    'scripts/smoke_budget_settings_validation.php',
    'scripts/smoke_card_favorites.php',
    'scripts/smoke_savings_plan.php',
    'scripts/smoke_recurring_schedule_change_validation.php',
    'scripts/smoke_google_verifier.php',
    'scripts/smoke_transaction_atomicity.php',
    'scripts/test_privacy_parity.php',
];

foreach ($scripts as $script) {
    $command = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($root . '/' . $script);
    passthru($command, $exitCode);

    if ($exitCode !== 0) {
        fwrite(STDERR, "Backend test suite failed in {$script}\n");
        exit($exitCode);
    }
}

fwrite(STDOUT, "Backend test suite passed\n");
