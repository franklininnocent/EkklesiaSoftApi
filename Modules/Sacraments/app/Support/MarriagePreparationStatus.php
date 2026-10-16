<?php

namespace Modules\Sacraments\Support;

final class MarriagePreparationStatus
{
    public const ACTIVE = 'active';

    public const COMPLETED = 'completed';

    public const WITHDRAWN = 'withdrawn';

    public const ALL = [
        self::ACTIVE,
        self::COMPLETED,
        self::WITHDRAWN,
    ];

    public static function normalize(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        $normalized = strtolower(trim($status));

        return in_array($normalized, self::ALL, true) ? $normalized : null;
    }

    public static function validationRule(): string
    {
        return 'in:'.implode(',', self::ALL);
    }
}
