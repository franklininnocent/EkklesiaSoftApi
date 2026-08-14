<?php

namespace Modules\Sacraments\Exceptions;

use Exception;

/**
 * Business-rule failure with stable API error code (plan §15).
 */
class SacramentBusinessRuleException extends Exception
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
        int $httpStatus = 422
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function httpStatus(): int
    {
        return $this->getCode() >= 400 ? $this->getCode() : 422;
    }

    /**
     * @return array<string, mixed>
     */
    public function toResponse(): array
    {
        return [
            'success' => false,
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'context' => $this->context,
        ];
    }
}
