<?php

namespace Modules\Sacraments\Support;

/**
 * Canonical sacramental record business statuses (ADR-07).
 * Soft-delete uses deleted_at and is not a status value.
 */
final class SacramentStatus
{
    public const REGISTERED = 'registered';

    public const CONDITIONAL = 'conditional';

    public const VOIDED = 'voided';

    /** @deprecated Legacy alias — normalize via normalize() */
    public const LEGACY_ACTIVE = 'active';

    /** @deprecated Legacy alias — normalize via normalize() */
    public const LEGACY_CANCELLED = 'cancelled';

    public const ALL = [
        self::REGISTERED,
        self::CONDITIONAL,
        self::VOIDED,
    ];

    public static function normalize(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        return match (strtolower(trim($status))) {
            self::LEGACY_ACTIVE, self::REGISTERED => self::REGISTERED,
            self::LEGACY_CANCELLED, self::VOIDED => self::VOIDED,
            self::CONDITIONAL => self::CONDITIONAL,
            default => $status,
        };
    }

    public static function isValid(?string $status): bool
    {
        $normalized = self::normalize($status);

        return $normalized !== null && in_array($normalized, self::ALL, true);
    }

    public static function validationRule(): string
    {
        return 'in:registered,conditional,voided,active,cancelled';
    }
}
