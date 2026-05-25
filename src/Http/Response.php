<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /** @param array<string, string> $headers */
    private function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
        private readonly mixed $stream = null
    ) {
    }

    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/json';
        return new self($status, (string) json_encode($data, JSON_UNESCAPED_SLASHES), $headers);
    }

    public static function noContent(): self
    {
        return new self(204, '', []);
    }

    /** @param array<string, string> $headers */
    public static function raw(string $body, int $status = 200, array $headers = []): self
    {
        return new self($status, $body, $headers);
    }

    /** @param callable():void $stream */
    public static function stream(callable $stream, int $status = 200, array $headers = []): self
    {
        return new self($status, '', $headers, $stream);
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[$name] = $value;

        return new self($this->status, $this->body, $headers, $this->stream);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }

        if (is_callable($this->stream)) {
            ($this->stream)();
            return;
        }

        if ($this->body !== '') {
            echo $this->body;
        }
    }
}
