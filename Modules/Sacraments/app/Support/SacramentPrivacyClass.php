<?php

namespace Modules\Sacraments\Support;

final class SacramentPrivacyClass
{
    public const STANDARD = 'standard';

    public const SENSITIVE = 'sensitive';

    public const RESTRICTED = 'restricted';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::STANDARD, self::SENSITIVE, self::RESTRICTED];
    }

    public static function isRestricted(?string $class): bool
    {
        return $class === self::RESTRICTED;
    }

    public static function isSensitive(?string $class): bool
    {
        return $class === self::SENSITIVE;
    }
}
