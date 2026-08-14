<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    if ($contents === false) throw new RuntimeException('Missing auth identity foundation file: ' . $path);
    return $contents;
};
$schema = $read('schema.sql');
$retirement = $read('migrations/20260815_retire_legacy_auth_representation.sql');
$diagnostic = $read('scripts/verify_auth_identity_retirement.php');
$runtimeAuth = $read('src/Auth/AuthApplicationService.php') . $read('src/Controllers/ProfileController.php');
$identityRepository = $read('src/Auth/AuthIdentityRepository.php');
$passwordRepository = $read('src/Auth/PasswordCredentialRepository.php');
$methods = $read('src/Auth/AuthMethodService.php');
$profile = $read('src/Controllers/ProfileController.php');
$openapi = $read('openapi.yaml');
$deployment = $read('scripts/deploy_production.sh');

foreach (['CREATE TABLE auth_identities', 'uq_auth_identities_provider_subject', 'uq_auth_identities_user_provider', 'fk_auth_identities_user', 'CREATE TABLE password_credentials', 'fk_password_credentials_user', 'last_authenticated_at DATETIME NULL'] as $term) {
    if (!str_contains($schema, $term)) throw new RuntimeException('Schema contract is missing: ' . $term);
}
if (!str_contains($schema, 'provider_subject VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL')) throw new RuntimeException('Provider subjects must remain case-sensitive');
$usersStart = (int) strpos($schema, 'CREATE TABLE users (');
$usersEnd = (int) strpos($schema, ') ENGINE', $usersStart);
$usersTable = substr($schema, $usersStart, $usersEnd - $usersStart);
foreach (['auth_provider', 'google_sub', 'password_hash', 'chk_users_auth_provider', 'uq_users_google_sub'] as $legacyTerm) {
    if (str_contains($usersTable, $legacyTerm)) throw new RuntimeException('Clean users schema retains retired legacy auth representation: ' . $legacyTerm);
}
foreach (['auth_retirement_preflight_failed', 'auth_retirement_zero_method_users', 'auth_retirement_multi_method_users', 'DROP COLUMN auth_provider', 'DROP COLUMN password_hash', 'DROP COLUMN google_sub'] as $term) {
    if (!str_contains($retirement, $term)) throw new RuntimeException('Retirement migration safety contract is missing: ' . $term);
}
foreach (['users_with_zero_methods', 'users_with_multiple_methods', 'auth_identity_orphans', 'session_user_orphans', '--user-id=ID'] as $term) {
    if (!str_contains($diagnostic, $term)) throw new RuntimeException('Retirement verifier contract is missing: ' . $term);
}
foreach (['findByProviderSubject', 'findForUser', 'markGoogleUsed', 'markUsed', 'last_authenticated_at'] as $term) {
    if (!str_contains($runtimeAuth, $term)) throw new RuntimeException('Piece 2 authority switch is missing: ' . $term);
}
foreach (['provider_subject', 'password_changed_at'] as $term) {
    if (!str_contains($identityRepository . $passwordRepository, $term)) throw new RuntimeException('Authority repository contract is missing: ' . $term);
}
foreach (['listForUser', 'hasPassword', 'hasExternalProvider', 'auth_method_invariant_violation'] as $term) {
    if (!str_contains($methods, $term)) throw new RuntimeException('Provider-neutral auth method domain is missing: ' . $term);
}
foreach (['getAuthMethods', '/me/auth-methods', 'AuthMethodsResponse'] as $term) {
    if (!str_contains($profile . $openapi, $term)) throw new RuntimeException('Auth method inventory API contract is missing: ' . $term);
}
foreach (['20260815_retire_legacy_auth_representation.sql', 'verify_auth_identity_retirement.php', 'RETIREMENT_PENDING'] as $term) {
    if (!str_contains($deployment, $term)) throw new RuntimeException('Destructive auth retirement deployment gate is missing: ' . $term);
}
echo "Auth identity authority contract tests passed\n";
