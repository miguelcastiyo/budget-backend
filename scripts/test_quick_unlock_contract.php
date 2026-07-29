<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

// Static contract canaries keep this check runnable in CI without requiring a
// production credential, a browser authenticator, or a database account.
$root = dirname(__DIR__);
$fail = static function (string $message): never { throw new RuntimeException($message); };
$read = static function (string $path) use ($root, $fail): string {
    $contents = @file_get_contents($root . '/' . $path);
    if ($contents === false) $fail('missing Quick Unlock contract file: ' . $path);
    return $contents;
};

$service = $read('src/Privacy/QuickUnlockService.php');
$schema = $read('schema.sql');
$openapi = $read('openapi.yaml');
$deviceMigration = $read('migrations/20260728_add_budget_device_identity.sql');
$quickUnlockMigration = $read('migrations/20260728_create_quick_unlock.sql');
$quickUnlockDeviceMigration = $read('migrations/20260728z_migrate_quick_unlock_device_identity.sql');

if (!str_contains($service, "gmdate('Y-m-d H:i:s'")) $fail('challenge expiry is not UTC-based');
if (preg_match('/\bdate\s*\(/', $service)) $fail('Quick Unlock uses a local-time date function');
foreach (['WEBAUTHN_RP_ID_NOT_CONFIGURED', 'WEBAUTHN_ORIGIN_NOT_CONFIGURED', 'WEBAUTHN_ORIGIN_INVALID', 'APP_ENV'] as $term) {
    if (!str_contains($service, $term)) $fail('production WebAuthn fail-closed check is missing: ' . $term);
}
foreach (['vault_quick_unlock_credentials', 'webauthn_challenges', 'idx_quick_unlock_user_device_status', 'idx_webauthn_challenges_lookup'] as $term) {
    if (!str_contains($schema, $term)) $fail('Quick Unlock schema contract is missing: ' . $term);
}
foreach (['/me/vault/quick-unlock', '/me/vault/quick-unlock/assertion/complete', '/me/devices/{device_id}'] as $term) {
    if (!str_contains($openapi, $term)) $fail('OpenAPI Quick Unlock contract is missing: ' . $term);
}
if (str_contains($deviceMigration, 'vault_quick_unlock_credentials')) $fail('device identity migration runs before the Quick Unlock table');
if (!str_contains($deviceMigration, 'IF NOT EXISTS') || !str_contains($quickUnlockDeviceMigration, 'vault_quick_unlock_credentials')) $fail('Quick Unlock migration ordering/idempotence contract is missing');
if (!str_contains($quickUnlockMigration, 'CREATE TABLE IF NOT EXISTS vault_quick_unlock_credentials')) $fail('Quick Unlock table migration is missing');
$serviceReflection = new ReflectionClass(App\Privacy\QuickUnlockService::class);
$binary = $serviceReflection->getMethod('binary');
$sample = random_bytes(32);
$encoded = rtrim(strtr(base64_encode($sample), '+/', '-_'), '=');
$decoded = $binary->invoke($serviceReflection->newInstanceWithoutConstructor(), $encoded, 'prf_input', 32, 32);
if (!hash_equals($sample, $decoded)) $fail('base64url PRF input decoding failed');
echo "Quick Unlock contract tests passed\n";
