<?php

declare(strict_types=1);

namespace App\Monitoring;

final class OperationalMetadata
{
    private const ALLOWED_KEYS = [
        'request_id', 'route', 'path', 'method', 'status', 'error_code', 'actor_user_id',
        'record_id', 'resource_id', 'resource_type', 'action', 'result', 'revision',
        'encryption_version', 'migration_status', 'duration_ms', 'affected_rows',
        'pagination', 'class', 'exception_class', 'file', 'line', 'code', 'http_code',
        'curl_errno', 'kid', 'fields', 'role', 'expires_at', 'user_agent', 'fingerprint',
        'total_rows', 'valid_rows', 'imported_rows', 'duplicate_rows', 'invalid_rows', 'skipped_rows', 'sync_sequence',
    ];
    private const NEVER_KEYS = [
        'authorization', 'cookie', 'set-cookie', 'token', 'jwt', 'session', 'csrf', 'password',
        'secret', 'api_key', 'key', 'private_key', 'recovery_key', 'wrapped_key', 'plaintext',
        'payload', 'request_body', 'response_body', 'amount', 'name', 'description', 'notes',
        'date', 'category', 'tag', 'card', 'income', 'balance', 'goal', 'transaction', 'csv_row',
        'csv_contents', 'financial', 'ciphertext', 'exception_context', 'old_value', 'new_value',
        'snapshot', 'email', 'body', 'message',
    ];

    /** @param array<string,mixed> $metadata */
    public static function allowList(array $metadata): array
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            $key = (string) $key;
            if (!in_array($key, self::ALLOWED_KEYS, true) || self::neverKey($key)) continue;
            $safe[$key] = self::value($value);
        }
        return $safe;
    }

    public static function message(string $message): string
    {
        $message = trim($message);
        if ($message === '' || preg_match('/(?:PRIVATE_|CANARY|amount|balance|income|transaction|notes|description|category|tag|card|csv[_ -]?row|authorization|token|secret|password|key)/i', $message) === 1) {
            return '[operational message omitted]';
        }
        return mb_substr($message, 0, 240);
    }

    private static function neverKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));
        foreach (self::NEVER_KEYS as $part) {
            if ($normalized === $part || str_contains($normalized, $part . '_') || str_ends_with($normalized, '_' . $part)) return true;
        }
        return false;
    }

    private static function value(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 3) return '[max-depth]';
        if (is_scalar($value) || $value === null) return is_string($value) ? mb_substr($value, 0, 240) : $value;
        if (!is_array($value)) return '[unsupported]';
        $out = [];
        foreach ($value as $key => $item) {
            $key = (string) $key;
            if (self::neverKey($key)) continue;
            $out[$key] = self::value($item, $depth + 1);
        }
        return $out;
    }
}
