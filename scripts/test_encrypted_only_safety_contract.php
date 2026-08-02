<?php

declare(strict_types=1);

/*
 * Permanent, database-free contract for the encrypted financial boundary.
 *
 * This test intentionally does not delete or disable compatibility routes. It
 * prevents future route additions from bypassing the existing policy boundary
 * and prevents accidental removal of the encrypted-record substrate.
 */

$root = dirname(__DIR__);
$app = file_get_contents($root . '/src/Core/App.php');
$schema = file_get_contents($root . '/schema.sql');

if ($app === false || $schema === false) {
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

$legacyPrefixes = [
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
];

foreach (preg_split('/\R/', $app) ?: [] as $line) {
    if (!str_contains($line, '$add(')) {
        continue;
    }
    $isLegacy = false;
    foreach ($legacyPrefixes as $prefix) {
        if (str_contains($line, "'{$prefix}")) {
            $isLegacy = true;
            break;
        }
    }
    if ($isLegacy && !str_contains($line, 'financialRead(') && !str_contains($line, 'financialMutation(')) {
        throw new RuntimeException('legacy financial route bypasses FinancialReadPolicy or FinancialWritePolicy');
    }
}

foreach (['encrypted_record_sync_state', 'encrypted_financial_records', 'encrypted_record_changes'] as $table) {
    if (!preg_match('/CREATE TABLE ' . preg_quote($table, '/') . '\s*\(/', $schema)) {
        throw new RuntimeException("encrypted persistence table is missing: {$table}");
    }
}

echo "Encrypted-only safety contract passed: {$routeCount} backend route registrations and encrypted persistence markers verified\n";
