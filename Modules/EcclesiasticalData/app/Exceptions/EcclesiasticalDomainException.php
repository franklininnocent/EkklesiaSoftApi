<?php

namespace Modules\EcclesiasticalData\Exceptions;

use RuntimeException;

class EcclesiasticalDomainException extends RuntimeException
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

    public static function notFound(string $message = 'Record not found.'): self
    {
        return new self($message, 404);
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    public static function conflict(string $message, array $errors = []): self
    {
        return new self($message, 409, $errors);
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    public static function validation(string $message, array $errors = []): self
    {
        return new self($message, 422, $errors);
    }
}
