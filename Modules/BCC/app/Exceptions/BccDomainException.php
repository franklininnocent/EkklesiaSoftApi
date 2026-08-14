<?php

namespace Modules\BCC\Exceptions;

use RuntimeException;

class BccDomainException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $errors = [],
    ) {
        parent::__construct($message, $httpStatus);
    }

    public static function notFound(string $message = 'BCC not found.'): self
    {
        return new self($message, 404);
    }

    public static function conflict(string $message, array $errors = []): self
    {
        return new self($message, 409, $errors);
    }
}
