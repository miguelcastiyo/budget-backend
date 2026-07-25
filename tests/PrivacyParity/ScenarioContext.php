<?php

declare(strict_types=1);

namespace PrivacyParity;

final class ScenarioContext
{
    public static function assertSafe(string $root, string $outputRoot): void
    {
        $fileValues = [];
        $envFile = $root . '/.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (str_contains($line, '=') && !str_starts_with(trim($line), '#')) {
                    [$key, $value] = explode('=', $line, 2);
                    $fileValues[trim($key)] = trim(trim($value), "\"'");
                }
            }
        }
        $appEnv = getenv('APP_ENV') ?: ($fileValues['APP_ENV'] ?? '');
        if (in_array(strtolower($appEnv), ['production', 'prod'], true)) {
            throw new \RuntimeException('Privacy parity refuses to run in production');
        }
        if (getenv('PRIVACY_PARITY_TEST') !== '1' && getenv('PRIVACY_PARITY_ALLOW_TEST') !== '1') {
            throw new \RuntimeException('Set PRIVACY_PARITY_TEST=1 to run the isolated parity harness');
        }
        $realRoot = realpath($root);
        $realOutput = realpath($outputRoot) ?: $outputRoot;
        if ($realRoot === false || !str_starts_with($realOutput, $realRoot . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Fixture output must remain inside the repository fixture directory');
        }
        $dsn = getenv('DB_DSN') ?: ($fileValues['DB_DSN'] ?? '');
        if (str_starts_with($dsn, 'mysql:')) {
            if (!preg_match('/dbname=([^;]+)/', $dsn, $match) || !preg_match('/_privacy_parity_test$/', $match[1])) {
                throw new \RuntimeException('Parity harness requires a dedicated *_privacy_parity_test MariaDB database');
            }
        } elseif ($dsn !== '' && !str_starts_with($dsn, 'sqlite:')) {
            throw new \RuntimeException('Parity harness accepts only isolated MariaDB or explicit test SQLite connections');
        }
    }
}
