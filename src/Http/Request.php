<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** @var array<string, string> */
    private array $headers;

    /** @var array<string, mixed>|null */
    private ?array $json = null;

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $rawBody,
        public readonly array $query,
        public readonly array $cookies,
        public readonly array $files,
        private readonly array $post,
        array $headers
    ) {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }
        $this->headers = $normalized;
    }

    public static function capture(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = (string) parse_url($uri, PHP_URL_PATH);

        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        if (!is_array($headers)) {
            $headers = [];
        }
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value) || !str_starts_with($key, 'HTTP_')) {
                continue;
            }
            $name = str_replace('_', '-', strtolower(substr($key, 5)));
            if (!isset($headers[$name])) {
                $headers[$name] = $value;
            }
        }

        return new self(
            method: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            path: $path,
            rawBody: (string) file_get_contents('php://input'),
            query: $_GET,
            cookies: $_COOKIE,
            files: $_FILES,
            post: $_POST,
            headers: $headers
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }

        if ($this->rawBody === '') {
            $this->json = [];
            return $this->json;
        }

        $decoded = json_decode($this->rawBody, true);
        if (!is_array($decoded)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Invalid JSON body');
        }

        $this->json = $decoded;
        return $this->json;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }

        $json = $this->json();
        return $json[$key] ?? $default;
    }
}
