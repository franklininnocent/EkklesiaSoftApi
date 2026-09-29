<?php

namespace Modules\Family\app\Exceptions;

use RuntimeException;

class HouseholdTransitionException extends RuntimeException
{
    public const FAMILY_NOT_FOUND = 'FAMILY_NOT_FOUND';

    public const TARGET_BCC_NOT_FOUND = 'TARGET_BCC_NOT_FOUND';

    public const TARGET_BCC_ALREADY_ASSIGNED = 'TARGET_BCC_ALREADY_ASSIGNED';

    public const TARGET_FAMILY_NOT_FOUND = 'TARGET_FAMILY_NOT_FOUND';

    public const FAMILY_ALREADY_MIGRATED = 'FAMILY_ALREADY_MIGRATED';

    public const HEAD_SUCCESSION_REQUIRED = 'HEAD_SUCCESSION_REQUIRED';

    public const INVALID_SUCCESSION_MEMBER = 'INVALID_SUCCESSION_MEMBER';

    public const MEMBER_NOT_IN_EXPECTED_FAMILY = 'MEMBER_NOT_IN_EXPECTED_FAMILY';

    public const MEMBER_ALREADY_IN_TARGET_FAMILY = 'MEMBER_ALREADY_IN_TARGET_FAMILY';

    public const SAME_MEMBER_SELECTED = 'SAME_MEMBER_SELECTED';

    public const DUPLICATE_PERSON = 'DUPLICATE_PERSON';

    public const SAME_FAMILY_MARRIAGE_NOT_ALLOWED = 'SAME_FAMILY_MARRIAGE_NOT_ALLOWED';

    public const TRANSITION_ALREADY_COMPLETED = 'TRANSITION_ALREADY_COMPLETED';

    public const TRANSITION_CONFLICT = 'TRANSITION_CONFLICT';

    public const INVALID_EFFECTIVE_DATE = 'INVALID_EFFECTIVE_DATE';

    public const CROSS_TENANT_RESOURCE = 'CROSS_TENANT_RESOURCE';

    public const UNAUTHORIZED_TRANSITION = 'UNAUTHORIZED_TRANSITION';

    public const INTERVAL_OVERLAP = 'INTERVAL_OVERLAP';

    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $errors = [],
    ) {
        parent::__construct($message, $httpStatus);
    }

    public static function notFound(string $code = self::FAMILY_NOT_FOUND, string $message = 'Resource not found.'): self
    {
        return new self($code, $message, 404);
    }

    public static function conflict(string $code, string $message, array $errors = []): self
    {
        return new self($code, $message, 409, $errors);
    }

    public static function validation(string $code, string $message, array $errors = []): self
    {
        return new self($code, $message, 422, $errors);
    }
}
