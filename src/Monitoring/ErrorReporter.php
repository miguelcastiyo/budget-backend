<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Core\Config;
use App\Http\Request;
use Throwable;

final class ErrorReporter
{
    public function __construct(
        private readonly Config $config,
        private readonly StructuredLogger $logger
    ) {
    }

    public function reportException(Request $request, Throwable $exception, int $status, string $requestId): void
    {
        $fingerprint = $this->fingerprint($request, $exception, $status);
        $context = [
            'request_id' => $requestId,
            'method' => $request->method,
            'route' => $request->path,
            'status' => $status,
            'exception_class' => $exception::class,
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'fingerprint' => $fingerprint,
        ];

        $this->logger->error(
            'server_error',
            sprintf('%s %s failed with %s', $request->method, $request->path, $exception::class),
            $context
        );

        $this->sendAlert($request, $exception, $status, $requestId, $fingerprint);
    }

    private function fingerprint(Request $request, Throwable $exception, int $status): string
    {
        return hash('sha256', implode('|', [
            'budget-api',
            (string) $status,
            $request->method,
            $request->path,
            $exception::class,
            $exception->getFile(),
            (string) $exception->getLine(),
        ]));
    }

    private function sendAlert(Request $request, Throwable $exception, int $status, string $requestId, string $fingerprint): void
    {
        $url = trim((string) $this->config->get('ERROR_ALERT_WEBHOOK_URL', ''));
        if ($url === '') {
            return;
        }

        $environment = (string) $this->config->get('APP_ENV', 'local');
        $summary = sprintf(
            'Budget API server error on %s %s (%s, request %s)',
            $request->method,
            $request->path,
            $environment,
            $requestId
        );

        $basePayload = [
            'service' => 'budget-api',
            'environment' => $environment,
            'event' => 'server_error',
            'severity' => 'error',
            'summary' => $summary,
            'request_id' => $requestId,
            'fingerprint' => $fingerprint,
            'status' => $status,
            'method' => $request->method,
            'path' => $request->path,
            'exception_class' => $exception::class,
            'timestamp' => gmdate('c'),
        ];

        $format = strtolower(trim((string) $this->config->get('ERROR_ALERT_WEBHOOK_FORMAT', 'json')));
        $payload = match ($format) {
            'discord' => ['content' => $summary, 'embeds' => [[
                'title' => 'Budget API server error',
                'description' => $summary,
                'color' => 13632027,
                'fields' => [
                    ['name' => 'Request ID', 'value' => $requestId, 'inline' => true],
                    ['name' => 'Status', 'value' => (string) $status, 'inline' => true],
                    ['name' => 'Exception', 'value' => $exception::class, 'inline' => false],
                    ['name' => 'Fingerprint', 'value' => $fingerprint, 'inline' => false],
                ],
            ]]],
            'slack' => ['text' => $summary, 'blocks' => [[
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => '*' . $summary . '*'],
            ], [
                'type' => 'section',
                'fields' => [
                    ['type' => 'mrkdwn', 'text' => "*Request ID*\n" . $requestId],
                    ['type' => 'mrkdwn', 'text' => "*Status*\n" . $status],
                    ['type' => 'mrkdwn', 'text' => "*Exception*\n" . $exception::class],
                    ['type' => 'mrkdwn', 'text' => "*Fingerprint*\n" . $fingerprint],
                ],
            ]]],
            default => $basePayload,
        };

        try {
            $this->postJson($url, $payload);
        } catch (Throwable $e) {
            $this->logger->warning('error_alert_failed', 'Could not send error alert webhook', [
                'request_id' => $requestId,
                'alert_error' => [
                    'class' => $e::class,
                    'message' => $e->getMessage(),
                ],
            ]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function postJson(string $url, array $payload): void
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new \RuntimeException('Could not encode webhook payload');
        }

        $timeout = max(1, $this->config->getInt('ERROR_ALERT_TIMEOUT_SECONDS', 2));

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            if ($curl === false) {
                throw new \RuntimeException('Could not initialize webhook request');
            }

            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
            ]);

            $result = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            if (\PHP_VERSION_ID < 80500) {
                curl_close($curl);
            }

            if ($result === false || $status >= 400) {
                throw new \RuntimeException($error !== '' ? $error : 'Webhook returned HTTP ' . $status);
            }

            return;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $result = file_get_contents($url, false, $context);
        if ($result === false) {
            throw new \RuntimeException('Webhook request failed');
        }

        $headers = $http_response_header ?? [];
        $statusLine = is_array($headers) ? (string) ($headers[0] ?? '') : '';
        if (preg_match('/\s([0-9]{3})\s/', $statusLine, $matches) === 1 && (int) $matches[1] >= 400) {
            throw new \RuntimeException('Webhook returned HTTP ' . $matches[1]);
        }
    }
}
