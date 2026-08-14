<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    if ($contents === false) throw new RuntimeException('Missing auth identity foundation file: ' . $path);
    return $contents;
};
$schema = $read('schema.sql');
$migration = $read('migrations/20260814_add_auth_identity_foundation.sql');
$diagnostic = $read('scripts/verify_auth_identity_backfill.php');
$runtimeAuth = $read('src/Auth/AuthApplicationService.php');

foreach (['CREATE TABLE auth_identities', 'uq_auth_identities_provider_subject', 'uq_auth_identities_user_provider', 'fk_auth_identities_user', 'CREATE TABLE password_credentials', 'fk_password_credentials_user', 'last_authenticated_at DATETIME NULL'] as $term) {
    if (!str_contains($schema, $term)) throw new RuntimeException('Schema contract is missing: ' . $term);
}
if (!str_contains($schema, 'provider_subject VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL')) throw new RuntimeException('Provider subjects must remain case-sensitive');
foreach (['CREATE TABLE IF NOT EXISTS auth_identities', 'CREATE TABLE IF NOT EXISTS password_credentials', 'auth_identity_backfill_preflight_failed', 'auth_identity_backfill_validation_failed', 'INSERT IGNORE INTO auth_identities', 'INSERT IGNORE INTO password_credentials', 'BINARY pc.password_hash = BINARY u.password_hash'] as $term) {
    if (!str_contains($migration, $term)) throw new RuntimeException('Migration safety contract is missing: ' . $term);
}
foreach (['google_mapping_mismatches', 'password_mapping_mismatches', 'orphan_auth_identities', 'ownership_foreign_key_tables', '--user-id=ID'] as $term) {
    if (!str_contains($diagnostic, $term)) throw new RuntimeException('Backfill diagnostic contract is missing: ' . $term);
}
if (str_contains($runtimeAuth, 'auth_identities') || str_contains($runtimeAuth, 'password_credentials')) {
    throw new RuntimeException('Piece 1 must not switch runtime authentication authority');
}
echo "Auth identity foundation contract tests passed\n";
