<?php

declare(strict_types=1);

/* Transition-only migration/repair code must not be constructed by public App wiring. */
$root = dirname(__DIR__);
$app = file_get_contents($root . '/src/Core/App.php');
$transitionReadme = file_get_contents($root . '/scripts/transition/README.md');
if ($app === false || $transitionReadme === false) {
    throw new RuntimeException('operator boundary could not read required sources');
}

foreach ([
    'PrivacyController',
    'PrivacyMigrationService',
    'PrivacyMigrationRepository',
    'MigrationSnapshotService',
    'MigrationStagingRepository',
    'PrivacyCutoverService',
    'PrivacyCleanupRepository',
    "'/me/privacy/migration",
] as $marker) {
    if (str_contains($app, $marker)) {
        throw new RuntimeException("public runtime contains transition-only dependency: {$marker}");
    }
}

foreach (['migration', 'cutover', 'cleanup', 'repair', 'operator-only'] as $term) {
    if (!str_contains(strtolower($transitionReadme), $term)) {
        throw new RuntimeException("transition documentation is missing operator boundary term: {$term}");
    }
}

echo "Operator boundary passed: transition migration/cutover dependencies are isolated from public App wiring\n";
