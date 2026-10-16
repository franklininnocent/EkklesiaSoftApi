<?php

namespace Modules\Sacraments\Support;

final class SacramentEventSubtype
{
    public const FIRST_COMMUNION = 'FIRST_COMMUNION';

    /**
     * @return list<string>
     */
    public static function forEucharist(): array
    {
        return [self::FIRST_COMMUNION];
    }

    public static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return strtoupper(str_replace([' ', '-'], '_', trim($value)));
    }
}
