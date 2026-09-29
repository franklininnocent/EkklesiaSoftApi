<?php

namespace Modules\Tenants\Exceptions;

use RuntimeException;

class ChurchLeadershipDomainException extends RuntimeException
{
    public const ROLE_ALREADY_EXISTS = 'ROLE_ALREADY_EXISTS';

    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $errors = [],
        public readonly ?string $errorCode = null,
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
    public static function conflict(string $message, array $errors = [], ?string $errorCode = null): self
    {
        return new self($message, 409, $errors, $errorCode);
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    public static function roleAlreadyExists(string $message = 'A leadership role with this name already exists.', array $errors = []): self
    {
        return self::conflict($message, $errors, self::ROLE_ALREADY_EXISTS);
    }
}
