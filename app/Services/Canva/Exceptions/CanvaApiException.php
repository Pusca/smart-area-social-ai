<?php

namespace App\Services\Canva\Exceptions;

use RuntimeException;

class CanvaApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly array $payload = []
    ) {
        parent::__construct($message, $statusCode);
    }
}
