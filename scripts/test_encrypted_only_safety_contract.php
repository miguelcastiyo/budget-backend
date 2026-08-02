<?php

declare(strict_types=1);

/*
 * Permanent, database-free contract for the encrypted financial boundary.
 *
 * This test enforces the Phase 3 public route boundary. Legacy financial
 * implementation remains available only to isolated operator tooling.
 */

$root = dirname(__DIR__);
$app = file_get_contents($root . '/src/Core/App.php');
$schema = file_get_contents($root . '/schema.sql');
$openapi = file_get_contents($root . '/openapi.yaml');

if ($app === false || $schema === false || $openapi === false) {
    throw new RuntimeException('encrypted-only safety contract could not read required source files');
}

$requiredEncryptedRoutes = [
    "POST /me/encrypted-records",
    "POST /me/encrypted-records/batch",
    "GET /me/encrypted-records/sync",
    "GET /me/encrypted-records/{record_id}",
    "PUT /me/encrypted-records/{record_id}",
    "DELETE /me/encrypted-records/{record_id}",
];

foreach ($requiredEncryptedRoutes as $route) {
    [$method, $path] = explode(' ', $route, 2);
    if (!str_contains($app, "\$add('{$method}', '{$path}'")) {
        throw new RuntimeException("required encrypted route is missing: {$route}");
    }
}

preg_match_all('/\$add\(\'(?:GET|POST|PUT|PATCH|DELETE)\', \'([^\']+)\'/', $app, $routeMatches);
$routeCount = count($routeMatches[0] ?? []);
if ($routeCount < count($requiredEncryptedRoutes)) {
    throw new RuntimeException("route registration surface is unexpectedly incomplete: {$routeCount}");
}

$retiredPrefixes = [
    '/me/budget-settings',
    '/me/tags',
    '/me/cards',
    '/me/contexts',
    '/me/recurring-expenses',
    '/me/transactions',
    '/me/months/',
    '/me/funds',
    '/me/month-closeouts',
    '/me/metrics/',
    '/me/dashboard',
    '/me/data-runs',
    '/me/imports/',
    '/me/privacy/migration',
];

foreach (preg_split('/\R/', $app) ?: [] as $line) {
    if (!str_contains($line, '$add(')) {
        continue;
    }
foreach ($retiredPrefixes as $prefix) {
        if (str_contains($line, "'{$prefix}")) {
            throw new RuntimeException("retired plaintext financial route is still registered: {$prefix}");
        }
    }
    if (str_contains($openapi, "  {$prefix}")) {
        throw new RuntimeException("retired plaintext route remains in openapi.yaml: {$prefix}");
    }
}

foreach (['FinancialReadPolicy', 'FinancialWritePolicy', 'financialRead(', 'financialMutation('] as $legacyBoundaryMarker) {
    if (str_contains($app, $legacyBoundaryMarker)) {
        throw new RuntimeException("retired plaintext route boundary remains in App.php: {$legacyBoundaryMarker}");
    }
}

foreach (['encrypted_record_sync_state', 'encrypted_financial_records', 'encrypted_record_changes'] as $table) {
    if (!preg_match('/CREATE TABLE ' . preg_quote($table, '/') . '\s*\(/', $schema)) {
        throw new RuntimeException("encrypted persistence table is missing: {$table}");
    }
}

echo "Encrypted-only safety contract passed: {$routeCount} backend route registrations and encrypted persistence markers verified\n";
