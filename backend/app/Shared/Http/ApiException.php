<?php

namespace App\Shared\Http;

use RuntimeException;

class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }
}
