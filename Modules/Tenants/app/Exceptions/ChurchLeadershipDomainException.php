<?php

namespace Modules\Tenants\Exceptions;

use RuntimeException;

class ChurchLeadershipDomainException extends RuntimeException
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

    public static function notFound(string $message = 'Leadership assignment not found.'): self
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
}
