<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Core\Config;
use JsonException;

final class StructuredLogger
{
    /** @var list<string> */
    private const SENSITIVE_KEY_PARTS = [
        'authorization',
        'cookie',
        'csrf',
        'key',
        'password',
        'secret',
        'session',
        'token',
    ];

    public function __construct(private readonly Config $config)
    {
    }

    /** @param array<string, mixed> $context */
    public function error(string $event, string $message, array $context = []): void
    {
        $this->log('error', $event, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $event, string $message, array $context = []): void
    {
        $this->log('warning', $event, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $event, string $message, array $context = []): void
    {
        error_log($this->format($level, $event, $message, $context));
    }

    /** @param array<string, mixed> $context */
    public function format(string $level, string $event, string $message, array $context = []): string
    {
        $entry = [
            'timestamp' => gmdate('c'),
            'level' => $level,
            'service' => 'budget-api',
            'environment' => $this->config->get('APP_ENV', 'local'),
            'event' => $event,
            'message' => $this->sanitizeString($message),
            'context' => $this->sanitizeValue($context),
        ];

        try {
            return (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '{"timestamp":"' . gmdate('c') . '","level":"error","service":"budget-api","event":"log_encoding_failed","message":"Could not encode structured log"}';
        }
    }

    private function sensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            if (str_contains($normalized, $part)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeString(string $value): string
    {
        $value = preg_replace('/(session|token|secret|password|api[_-]?key)=([^\\s&]+)/i', '$1=[redacted]', $value) ?? $value;

        if (strlen($value) > 2000) {
            return substr($value, 0, 2000) . '...';
        }

        return $value;
    }

    private function sanitizeValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 6) {
            return '[max-depth]';
        }

        if (is_string($value)) {
            return $this->sanitizeString($value);
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if (!is_array($value)) {
            return '[unsupported]';
        }

        $sanitized = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if ($count >= 50) {
                $sanitized['truncated'] = true;
                break;
            }

            $outputKey = is_string($key) ? $key : (string) $key;
            $sanitized[$outputKey] = $this->sensitiveKey($outputKey)
                ? '[redacted]'
                : $this->sanitizeValue($item, $depth + 1);
            $count++;
        }

        return $sanitized;
    }
}
