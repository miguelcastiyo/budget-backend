<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use Throwable;

final class HealthController
{
    public function __invoke(Request $request): Response
    {
        return $this->liveness($request);
    }

    public function liveness(Request $request): Response
    {
        return $this->withSecurityHeaders($request, Response::json([
            'ok' => true,
            'service' => 'budget-api',
            'check' => 'health',
            'time' => gmdate('c'),
        ]));
    }

    public function readiness(Request $request, callable $databaseCheck): Response
    {
        try {
            $databaseCheck();

            return $this->withSecurityHeaders($request, Response::json([
                'ok' => true,
                'service' => 'budget-api',
                'check' => 'ready',
                'dependencies' => [
                    'database' => 'ok',
                ],
                'time' => gmdate('c'),
            ]));
        } catch (Throwable $e) {
            error_log('[budget-api] readiness check failed with ' . $e::class . ': ' . $e->getMessage());

            return $this->withSecurityHeaders($request, Response::json([
                'ok' => false,
                'service' => 'budget-api',
                'check' => 'ready',
                'dependencies' => [
                    'database' => 'error',
                ],
                'time' => gmdate('c'),
            ], 503));
        }
    }

    private function withSecurityHeaders(Request $request, Response $response): Response
    {
        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->withHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        $forwardedProto = strtolower(trim((string) ($request->header('X-Forwarded-Proto') ?? '')));
        if ($https === 'on' || $https === '1' || $forwardedProto === 'https') {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
