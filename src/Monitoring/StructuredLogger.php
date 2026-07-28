<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Core\Config;
use JsonException;

final class StructuredLogger
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @param array<string,mixed> $context */
    public function error(string $event, string $message, array $context = []): void
    {
        $this->log('error', $event, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $event, string $message, array $context = []): void
    {
        $this->log('warning', $event, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function log(string $level, string $event, string $message, array $context = []): void
    {
        error_log($this->format($level, $event, $message, $context));
    }

    /** @param array<string,mixed> $context */
    public function format(string $level, string $event, string $message, array $context = []): string
    {
        $entry = [
            'timestamp' => gmdate('c'),
            'level' => $level,
            'service' => 'budget-api',
            'environment' => $this->config->get('APP_ENV', 'local'),
            'event' => $event,
            'message' => OperationalMetadata::message($message),
            'context' => OperationalMetadata::allowList($context),
        ];
        try {
            return (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '{"level":"error","event":"log_encoding_failed","message":"Could not encode structured log"}';
        }
    }
}
