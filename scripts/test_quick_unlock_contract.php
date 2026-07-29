<?php

declare(strict_types=1);

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
$frontend = @file_get_contents(dirname($root) . '/budget-frontend/lib/privacy/quick-unlock.ts');
if ($frontend === false) $fail('missing frontend Quick Unlock module');

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
foreach (['quickUnlockRegistrationCredentialOptions', 'quickUnlockAssertionCredentialOptions', 'wrapped_vault_key'] as $term) {
    if (!str_contains($frontend, $term)) $fail('frontend Quick Unlock serializer contract is missing: ' . $term);
}
foreach (['localStorage', 'sessionStorage', 'indexedDB', 'console.log'] as $forbidden) {
    if (stripos($frontend, $forbidden) !== false) $fail('Quick Unlock module persists/logs forbidden material: ' . $forbidden);
}

echo "Quick Unlock contract tests passed\n";
