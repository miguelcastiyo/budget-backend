<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

final class HttpException extends RuntimeException
{
    /** @var list<array{field:string,message:string}> */
    private array $details;

    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        array $details = []
    ) {
        parent::__construct($message);
        $this->details = $details;
    }

    /** @return list<array{field:string,message:string}> */
    public function details(): array
    {
        return $this->details;
    }
}
