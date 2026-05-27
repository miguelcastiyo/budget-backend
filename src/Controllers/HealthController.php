<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Monitoring\StructuredLogger;
use Throwable;

final class HealthController
{
    public function __construct(private readonly ?StructuredLogger $logger = null)
    {
    }

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
            $this->logger?->error('readiness_check_failed', 'Database readiness check failed', [
                'exception' => [
                    'class' => $e::class,
                    'message' => $e->getMessage(),
                ],
            ]);

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
            ->withHeader('X-Request-ID', $this->requestId($request))
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

    private function requestId(Request $request): string
    {
        $incoming = trim((string) ($request->header('X-Request-ID') ?? ''));
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $incoming) === 1) {
            return $incoming;
        }

        return 'req_' . bin2hex(random_bytes(12));
    }
}
