<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Config;
use App\Http\Request;
use App\Monitoring\ErrorReporter;
use App\Monitoring\OperationalMetadata;
use App\Monitoring\StructuredLogger;
use App\Security\AuditLogger;

$canaries = [
    'PRIVATE_TX_NAME_CANARY_8C21',
    'PRIVATE_AMOUNT_CANARY_9417',
    'PRIVATE_NOTES_CANARY_DA31',
    'PRIVATE_TAG_CANARY_F992',
    'AUTH_SECRET_CANARY_63AB',
    'RECOVERY_KEY_CANARY_A741',
];
$fail = static function (string $message): never { throw new RuntimeException($message); };
$assertNoCanary = static function (string $value) use ($canaries, $fail): void {
    foreach ($canaries as $canary) {
        if (str_contains($value, $canary)) $fail('privacy canary leaked: ' . $canary);
    }
};

$metadata = OperationalMetadata::allowList([
    'request_id' => 'req_canary_safe',
    'route' => '/api/v1/me/transactions/{id}',
    'status' => 422,
    'actor_user_id' => 42,
    'record_id' => 'txn_opaque_id',
    'amount' => $canaries[1],
    'notes' => $canaries[2],
    'authorization' => $canaries[4],
    'payload' => ['transaction' => $canaries[0]],
]);
if (($metadata['request_id'] ?? null) !== 'req_canary_safe' || isset($metadata['amount'], $metadata['notes'], $metadata['authorization'], $metadata['payload'])) {
    $fail('operational metadata allow-list failed');
}

$config = Config::load(dirname(__DIR__));
$logger = new StructuredLogger($config);
$formatted = $logger->format('error', 'validation_failed', 'Rejected ' . $canaries[0], [
    'request_id' => 'req_canary_safe',
    'route' => '/api/v1/me/transactions/{id}',
    'error_code' => 'VALIDATION_FAILED',
    'transaction_name' => $canaries[0],
    'amount' => $canaries[1],
    'authorization' => $canaries[4],
]);
$assertNoCanary($formatted);
if (!str_contains($formatted, 'req_canary_safe') || !str_contains($formatted, 'VALIDATION_FAILED')) $fail('allowed log metadata was lost');

$auditReflection = new ReflectionClass(AuditLogger::class);
$audit = $auditReflection->newInstanceWithoutConstructor();
$redact = $auditReflection->getMethod('redactMetadata');
$auditMetadata = $redact->invoke($audit, ['amount' => $canaries[1], 'notes' => $canaries[2], 'request_id' => 'req_audit_safe']);
if ($auditMetadata !== ['request_id' => 'req_audit_safe']) $fail('audit allow-list failed');

$logPath = tempnam(sys_get_temp_dir(), 'privacy-boundary-');
if ($logPath === false) $fail('could not create temporary log');
try {
    ini_set('error_log', $logPath);
    $reporter = new ErrorReporter($config, $logger);
    $request = new Request('POST', '/api/v1/me/transactions/{id}', json_encode(['amount' => $canaries[1], 'notes' => $canaries[2]], JSON_THROW_ON_ERROR), [], [], [], [], ['Authorization' => $canaries[4]]);
    $reporter->reportException($request, new RuntimeException($canaries[0]), 500, 'req_exception_safe');
    $logged = (string) file_get_contents($logPath);
    $assertNoCanary($logged);
} finally {
    if (is_file($logPath)) unlink($logPath);
}

$tempPath = tempnam(sys_get_temp_dir(), 'privacy-csv-');
if ($tempPath === false) $fail('could not create temporary CSV');
try {
    file_put_contents($tempPath, $canaries[0] . ',' . $canaries[1]);
    if (!is_file($tempPath)) $fail('temporary CSV was not created');
} finally {
    if (is_file($tempPath)) unlink($tempPath);
}
if (is_file($tempPath)) $fail('temporary CSV cleanup failed');

echo "Operational privacy boundary tests passed\n";
