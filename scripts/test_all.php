<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$scripts = [
    'scripts/test_core.php',
    'scripts/test_auth_identity_foundation.php',
    'scripts/test_auth_method_domain.php',
    'scripts/test_session_reauthentication.php',
    'scripts/smoke_google_verifier.php',
    'scripts/test_privacy_parity.php',
    'scripts/test_privacy_operational_boundaries.php',
    'scripts/check_privacy_logging_patterns.php',
    'scripts/test_quick_unlock_contract.php',
    'scripts/test_encrypted_only_safety_contract.php',
    'scripts/test_runtime_source_boundary.php',
    'scripts/test_setup_status_contract.php',
    'scripts/test_operator_boundary.php',
    'scripts/test_phase4_schema_contract.php',
    'scripts/test_user_pruning_contract.php',
    'scripts/test_master_api_key_retirement.php',
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
