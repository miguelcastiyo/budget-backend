<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    /** @var array<string, string> */
    private array $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(string $rootPath): self
    {
        $values = [];
        $envPath = $rootPath . '/.env';

        if (is_file($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                $parts = explode('=', $line, 2);
                if (count($parts) !== 2) {
                    continue;
                }

                $key = trim($parts[0]);
                $value = trim($parts[1]);
                $value = trim($value, "\"'");
                $values[$key] = $value;
            }
        }

        foreach ($_ENV as $k => $v) {
            if (is_string($v)) {
                $values[$k] = $v;
            }
        }

        return new self($values);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = strtolower((string) ($this->values[$key] ?? ''));
        if ($value === '') {
            return $default;
        }

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public function getInt(string $key, int $default): int
    {
        $value = $this->values[$key] ?? null;
        if ($value === null || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }
}
