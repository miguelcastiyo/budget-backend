<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Http\HttpException;

final class GoogleTokenVerifier
{
    private const DEFAULT_CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';

    /** @var list<string> */
    private array $allowedClientIds;

    public function __construct(private readonly Config $config)
    {
        $raw = trim((string) $this->config->get('GOOGLE_CLIENT_IDS', ''));
        $parts = array_map('trim', explode(',', $raw));
        $this->allowedClientIds = array_values(array_filter($parts, static fn(string $v): bool => $v !== ''));
    }

    /** @return array{email:string,google_sub:string,name:?string,picture:?string} */
    public function verifyIdToken(string $idToken): array
    {
        if ($idToken === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', 'google_id_token is required');
        }

        if ($this->allowedClientIds === []) {
            throw new HttpException(500, 'INTERNAL_ERROR', 'GOOGLE_CLIENT_IDS is not configured');
        }

        if (!function_exists('openssl_verify') || !function_exists('openssl_pkey_get_public')) {
            throw new HttpException(500, 'INTERNAL_ERROR', 'OpenSSL is required for Google token verification');
        }

        [$header, $payload, $signingInput, $signature] = $this->parseJwt($idToken);

        $alg = (string) ($header['alg'] ?? '');
        if ($alg !== 'RS256') {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Unsupported Google token algorithm');
        }

        $kid = trim((string) ($header['kid'] ?? ''));
        if ($kid === '') {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Google token is missing key id');
        }

        $certificate = $this->certificateForKid($kid);
        $this->verifySignature($signingInput, $signature, $certificate);

        $issuer = (string) ($payload['iss'] ?? '');
        if (!in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Invalid Google token issuer');
        }

        if (!$this->audienceMatches($payload['aud'] ?? null)) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Google token audience mismatch');
        }

        $clockSkew = max(0, $this->config->getInt('GOOGLE_ID_TOKEN_CLOCK_SKEW_SECONDS', 300));
        $now = time();

        $exp = isset($payload['exp']) && is_numeric($payload['exp']) ? (int) $payload['exp'] : 0;
        if ($exp <= ($now - $clockSkew)) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Google token expired');
        }

        if (isset($payload['nbf']) && is_numeric($payload['nbf']) && (int) $payload['nbf'] > ($now + $clockSkew)) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Google token is not yet valid');
        }

        if (isset($payload['iat']) && is_numeric($payload['iat']) && (int) $payload['iat'] > ($now + $clockSkew)) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Google token issued-at time is invalid');
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $sub = trim((string) ($payload['sub'] ?? ''));
        $emailVerified = $payload['email_verified'] ?? false;
        $emailVerifiedBool = ($emailVerified === true || $emailVerified === 'true' || $emailVerified === '1' || $emailVerified === 1);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $sub === '' || !$emailVerifiedBool) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Google token missing required claims');
        }

        $name = trim((string) ($payload['name'] ?? ''));
        $picture = trim((string) ($payload['picture'] ?? ''));

        return [
            'email' => $email,
            'google_sub' => $sub,
            'name' => $name !== '' ? $name : null,
            'picture' => $picture !== '' ? $picture : null,
        ];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>,2:string,3:string} */
    private function parseJwt(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Invalid Google token format');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $signingInput = $encodedHeader . '.' . $encodedPayload;

        $header = $this->decodeJwtJsonPart($encodedHeader, 'header');
        $payload = $this->decodeJwtJsonPart($encodedPayload, 'payload');
        $signature = $this->base64UrlDecode($encodedSignature);

        return [$header, $payload, $signingInput, $signature];
    }

    /** @return array<string,mixed> */
    private function decodeJwtJsonPart(string $segment, string $label): array
    {
        $decoded = $this->base64UrlDecode($segment);
        $json = json_decode($decoded, true);
        if (!is_array($json)) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Invalid Google token ' . $label);
        }

        return $json;
    }

    private function base64UrlDecode(string $value): string
    {
        $normalized = strtr($value, '-_', '+/');
        $remainder = strlen($normalized) % 4;
        if ($remainder > 0) {
            $normalized .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Invalid Google token encoding');
        }

        return $decoded;
    }

    private function verifySignature(string $signingInput, string $signature, string $certificate): void
    {
        $publicKey = openssl_pkey_get_public($certificate);
        if ($publicKey === false) {
            throw new HttpException(500, 'INTERNAL_ERROR', 'Could not load Google signing certificate');
        }

        $verified = openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);

        if ($verified !== 1) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Invalid Google token signature');
        }
    }

    private function audienceMatches(mixed $audienceClaim): bool
    {
        if (is_string($audienceClaim)) {
            return in_array($audienceClaim, $this->allowedClientIds, true);
        }

        if (!is_array($audienceClaim)) {
            return false;
        }

        foreach ($audienceClaim as $audience) {
            if (is_string($audience) && in_array($audience, $this->allowedClientIds, true)) {
                return true;
            }
        }

        return false;
    }

    private function certificateForKid(string $kid): string
    {
        $cache = $this->loadCertificateCache();
        if ($this->cacheIsFresh($cache) && isset($cache['certs'][$kid]) && is_string($cache['certs'][$kid])) {
            return $cache['certs'][$kid];
        }

        try {
            $cache = $this->refreshCertificateCache();
            if (isset($cache['certs'][$kid]) && is_string($cache['certs'][$kid])) {
                return $cache['certs'][$kid];
            }
        } catch (HttpException $e) {
            if ($this->cacheCanBeUsedStale($cache) && isset($cache['certs'][$kid]) && is_string($cache['certs'][$kid])) {
                error_log(sprintf(
                    '[budget-api] Using stale Google signing certificate cache for kid=%s after refresh failure: %s',
                    $kid,
                    $e->getMessage()
                ));
                return $cache['certs'][$kid];
            }

            throw $e;
        }

        if ($this->cacheCanBeUsedStale($cache) && isset($cache['certs'][$kid]) && is_string($cache['certs'][$kid])) {
            error_log(sprintf(
                '[budget-api] Using stale Google signing certificate cache for unknown refreshed kid=%s',
                $kid
            ));
            return $cache['certs'][$kid];
        }

        throw new HttpException(401, 'UNAUTHENTICATED', 'Unknown Google token signing key');
    }

    /** @return array{fetched_at:int,expires_at:int,certs:array<string,string>}|null */
    private function loadCertificateCache(): ?array
    {
        $path = $this->certificateCachePath();
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $fetchedAt = isset($decoded['fetched_at']) && is_numeric($decoded['fetched_at']) ? (int) $decoded['fetched_at'] : 0;
        $expiresAt = isset($decoded['expires_at']) && is_numeric($decoded['expires_at']) ? (int) $decoded['expires_at'] : 0;
        $certsRaw = is_array($decoded['certs'] ?? null) ? $decoded['certs'] : [];

        $certs = [];
        foreach ($certsRaw as $kid => $certificate) {
            if (!is_string($kid) || !is_string($certificate) || trim($certificate) === '') {
                continue;
            }

            $certs[$kid] = $certificate;
        }

        if ($certs === []) {
            return null;
        }

        return [
            'fetched_at' => $fetchedAt,
            'expires_at' => $expiresAt,
            'certs' => $certs,
        ];
    }

    private function cacheIsFresh(?array $cache): bool
    {
        return $cache !== null && ($cache['expires_at'] ?? 0) > time();
    }

    private function cacheCanBeUsedStale(?array $cache): bool
    {
        if ($cache === null) {
            return false;
        }

        $staleTtl = max(0, $this->config->getInt('GOOGLE_CERTS_STALE_TTL_SECONDS', 86400));
        return ($cache['expires_at'] ?? 0) + $staleTtl > time();
    }

    /** @return array{fetched_at:int,expires_at:int,certs:array<string,string>} */
    private function refreshCertificateCache(): array
    {
        $url = trim((string) $this->config->get('GOOGLE_CERTS_URL', self::DEFAULT_CERTS_URL));
        if ($url === '') {
            throw new HttpException(500, 'INTERNAL_ERROR', 'GOOGLE_CERTS_URL is not configured');
        }

        [$certs, $expiresAt] = $this->fetchCertificates($url);
        $cache = [
            'fetched_at' => time(),
            'expires_at' => $expiresAt,
            'certs' => $certs,
        ];

        $this->persistCertificateCache($cache);
        return $cache;
    }

    /** @return array{0:array<string,string>,1:int} */
    private function fetchCertificates(string $url): array
    {
        $headers = [];
        $ch = curl_init($url);
        if ($ch === false) {
            throw new HttpException(500, 'INTERNAL_ERROR', 'Could not initialize Google certificate fetch');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$headers): int {
                $trimmed = trim($headerLine);
                if ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($headerLine);
            },
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);

        if ($body === false) {
            error_log(sprintf(
                '[budget-api] Google certificate fetch failed (errno=%d, http_code=%d): %s',
                $curlErrno,
                $httpCode,
                $curlError
            ));
            curl_close($ch);
            throw new HttpException(500, 'INTERNAL_ERROR', 'Google certificate fetch failed: ' . $curlError);
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            error_log(sprintf(
                '[budget-api] Google certificate fetch returned invalid JSON (http_code=%d, body_prefix=%s)',
                $httpCode,
                substr($body, 0, 200)
            ));
            curl_close($ch);
            throw new HttpException(500, 'INTERNAL_ERROR', 'Google certificate fetch returned invalid JSON');
        }

        if ($httpCode >= 400) {
            error_log(sprintf(
                '[budget-api] Google certificate fetch failed with http_code=%d',
                $httpCode
            ));
            curl_close($ch);
            throw new HttpException(500, 'INTERNAL_ERROR', 'Google certificate service unavailable');
        }

        $certs = [];
        foreach ($json as $kid => $certificate) {
            if (!is_string($kid) || !is_string($certificate) || trim($certificate) === '') {
                continue;
            }

            $certs[$kid] = $certificate;
        }

        curl_close($ch);
        if ($certs === []) {
            throw new HttpException(500, 'INTERNAL_ERROR', 'Google certificate response did not include usable keys');
        }

        return [$certs, $this->cacheExpiryFromHeaders($headers)];
    }

    /** @param array<string,string> $headers */
    private function cacheExpiryFromHeaders(array $headers): int
    {
        $defaultTtl = 3600;
        $cacheControl = strtolower((string) ($headers['cache-control'] ?? ''));
        if (preg_match('/max-age=(\d+)/', $cacheControl, $matches) === 1) {
            return time() + max(60, (int) $matches[1]);
        }

        return time() + $defaultTtl;
    }

    /** @param array{fetched_at:int,expires_at:int,certs:array<string,string>} $cache */
    private function persistCertificateCache(array $cache): void
    {
        $path = $this->certificateCachePath();
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpException(500, 'INTERNAL_ERROR', 'Could not initialize Google certificate cache storage');
        }

        $tmpPath = $path . '.tmp';
        $encoded = json_encode($cache, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || file_put_contents($tmpPath, $encoded, LOCK_EX) === false || !rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new HttpException(500, 'INTERNAL_ERROR', 'Could not persist Google certificate cache');
        }
    }

    private function certificateCachePath(): string
    {
        $relative = trim((string) $this->config->get('GOOGLE_CERTS_CACHE_PATH', 'storage/google-certs-cache.json'));
        $root = dirname(__DIR__, 2);

        if ($relative === '' || str_starts_with($relative, '/')) {
            return $relative !== '' ? $relative : ($root . '/storage/google-certs-cache.json');
        }

        return $root . '/' . $relative;
    }
}
