<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Http\HttpException;

final class GoogleTokenVerifier
{
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

        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken);
        $payload = $this->fetchJson($url);

        if (isset($payload['error_description']) || isset($payload['error'])) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Invalid Google ID token');
        }

        $issuer = (string) ($payload['iss'] ?? '');
        if (!in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Invalid Google token issuer');
        }

        $aud = (string) ($payload['aud'] ?? '');
        if (!in_array($aud, $this->allowedClientIds, true)) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Google token audience mismatch');
        }

        $exp = isset($payload['exp']) ? (int) $payload['exp'] : 0;
        if ($exp <= time()) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Google token expired');
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

    /** @return array<string,mixed> */
    private function fetchJson(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new HttpException(500, 'INTERNAL_ERROR', 'Could not initialize Google token verification');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($body === false) {
            throw new HttpException(500, 'INTERNAL_ERROR', 'Google token verification request failed: ' . $curlError);
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new HttpException(500, 'INTERNAL_ERROR', 'Google token verification returned invalid JSON');
        }

        if ($httpCode >= 500) {
            throw new HttpException(500, 'INTERNAL_ERROR', 'Google token verification service unavailable');
        }

        return $json;
    }
}
