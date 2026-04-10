<?php

declare(strict_types=1);

use App\Auth\GoogleTokenVerifier;
use App\Core\Config;

require __DIR__ . '/../src/bootstrap.php';

$tempDir = sys_get_temp_dir() . '/budget-google-verifier-' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
    fwrite(STDERR, "Could not create temp dir\n");
    exit(1);
}

$cachePath = $tempDir . '/google-certs-cache.json';
$privateKey = openssl_pkey_new([
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
]);

if ($privateKey === false) {
    fwrite(STDERR, "Could not create private key\n");
    exit(1);
}

$csr = openssl_csr_new(['commonName' => 'budget-google-test'], $privateKey, ['digest_alg' => 'sha256']);
$x509 = openssl_csr_sign($csr, null, $privateKey, 1, ['digest_alg' => 'sha256']);
if ($csr === false || $x509 === false) {
    fwrite(STDERR, "Could not create test certificate\n");
    exit(1);
}

$certificatePem = '';
openssl_x509_export($x509, $certificatePem);

$kid = 'test-kid';
$cache = [
    'fetched_at' => time(),
    'expires_at' => time() + 3600,
    'certs' => [
        $kid => $certificatePem,
    ],
];

file_put_contents($cachePath, (string) json_encode($cache, JSON_UNESCAPED_SLASHES));

$_ENV['GOOGLE_CLIENT_IDS'] = 'test-client-id.apps.googleusercontent.com';
$_ENV['GOOGLE_CERTS_CACHE_PATH'] = $cachePath;
$_ENV['GOOGLE_CERTS_URL'] = 'http://127.0.0.1:9/certs';
$_ENV['GOOGLE_ID_TOKEN_CLOCK_SKEW_SECONDS'] = '60';

$header = [
    'alg' => 'RS256',
    'typ' => 'JWT',
    'kid' => $kid,
];

$payload = [
    'iss' => 'https://accounts.google.com',
    'aud' => 'test-client-id.apps.googleusercontent.com',
    'exp' => time() + 300,
    'iat' => time() - 10,
    'email' => 'user@example.com',
    'email_verified' => true,
    'sub' => 'google-sub-123',
    'name' => 'Budget User',
    'picture' => 'https://example.com/avatar.png',
];

$encodedHeader = rtrim(strtr(base64_encode((string) json_encode($header, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
$encodedPayload = rtrim(strtr(base64_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
$signingInput = $encodedHeader . '.' . $encodedPayload;

$signature = '';
$signed = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
if (!$signed) {
    fwrite(STDERR, "Could not sign test token\n");
    exit(1);
}

$encodedSignature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
$jwt = $signingInput . '.' . $encodedSignature;

$config = Config::load(dirname(__DIR__));
$verifier = new GoogleTokenVerifier($config);
$identity = $verifier->verifyIdToken($jwt);

$expected = [
    'email' => 'user@example.com',
    'google_sub' => 'google-sub-123',
    'name' => 'Budget User',
    'picture' => 'https://example.com/avatar.png',
];

if ($identity !== $expected) {
    fwrite(STDERR, "Verifier returned unexpected identity\n");
    exit(1);
}

fwrite(STDOUT, "GoogleTokenVerifier smoke test passed\n");
