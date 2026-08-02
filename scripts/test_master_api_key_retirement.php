<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    if ($contents === false) throw new RuntimeException("Could not read {$path}");
    return $contents;
};

$auth = $read('src/Auth/AuthService.php');
$app = $read('src/Core/App.php');
$schema = $read('schema.sql');
$openapi = $read('openapi.yaml');
$env = $read('.env.example');
$migration = $read('migrations/20260802_remove_master_api_keys.sql');

foreach (['X-API-Key', 'master_api_keys', 'authenticateApiKey', 'api-key:'] as $marker) {
    if (str_contains($auth, $marker)) throw new RuntimeException("runtime auth still contains {$marker}");
}
foreach (['master-api-keys', 'MasterApiKeyController', 'X-API-Key'] as $marker) {
    if (str_contains($app, $marker)) throw new RuntimeException("public runtime still contains {$marker}");
}
if (preg_match('/^CREATE TABLE master_api_keys\b/m', $schema) === 1) {
    throw new RuntimeException('canonical schema still creates master_api_keys');
}
foreach (['masterApiKeyAuth', '/me/master-api-keys', 'CreateMasterApiKeyRequest', 'MasterApiKeyMetadata'] as $marker) {
    if (str_contains($openapi, $marker)) throw new RuntimeException("OpenAPI still contains {$marker}");
}
foreach (['MASTER_API_KEY', 'RATE_LIMIT_API_KEY'] as $marker) {
    if (str_contains($env, $marker)) throw new RuntimeException("environment example still contains {$marker}");
}
if (!str_contains($schema, "ENUM('session', 'api_key', 'system')")) {
    throw new RuntimeException('canonical audit schema no longer accepts historical api_key values');
}
if (trim($migration) !== "-- Retire master API-key credentials after runtime support has been removed.\n-- Historical audit rows remain in audit_logs, including actor_auth_type=api_key.\nDROP TABLE IF EXISTS master_api_keys;") {
    throw new RuntimeException('retirement migration does not match the narrow table-drop contract');
}

echo "Master API-key retirement contract passed\n";
