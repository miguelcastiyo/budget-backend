<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Config;
use App\Http\HttpException;

final class RateLimiter
{
    public function __construct(private readonly Config $config)
    {
    }

    public function hit(string $key, int $maxAttempts, int $windowSeconds): void
    {
        if ($maxAttempts <= 0 || $windowSeconds <= 0) {
            return;
        }

        $storeDir = $this->storagePath();
        if (!is_dir($storeDir) && !mkdir($storeDir, 0775, true) && !is_dir($storeDir)) {
            throw new HttpException(500, 'INTERNAL_ERROR', 'Could not initialize rate limiter storage');
        }

        $filename = $storeDir . '/' . hash('sha256', $key) . '.json';
        $fp = fopen($filename, 'c+');
        if ($fp === false) {
            throw new HttpException(500, 'INTERNAL_ERROR', 'Could not open rate limiter state');
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                throw new HttpException(500, 'INTERNAL_ERROR', 'Could not lock rate limiter state');
            }

            $now = time();
            $raw = stream_get_contents($fp);
            $state = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;

            $windowStart = (is_array($state) && isset($state['window_start']) && is_numeric($state['window_start']))
                ? (int) $state['window_start']
                : $now;
            $count = (is_array($state) && isset($state['count']) && is_numeric($state['count']))
                ? (int) $state['count']
                : 0;

            if (($now - $windowStart) >= $windowSeconds) {
                $windowStart = $now;
                $count = 0;
            }

            $count++;

            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, (string) json_encode([
                'window_start' => $windowStart,
                'count' => $count,
            ], JSON_UNESCAPED_SLASHES));
            fflush($fp);

            if ($count > $maxAttempts) {
                throw new HttpException(429, 'RATE_LIMITED', 'Too many attempts. Please try again later.');
            }
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function storagePath(): string
    {
        $relative = trim((string) $this->config->get('RATE_LIMIT_STORAGE_PATH', 'storage/rate-limit'));
        $root = dirname(__DIR__, 2);

        if ($relative === '' || str_starts_with($relative, '/')) {
            return $relative !== '' ? $relative : ($root . '/storage/rate-limit');
        }

        return $root . '/' . $relative;
    }
}
