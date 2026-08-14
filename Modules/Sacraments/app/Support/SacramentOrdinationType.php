<?php

namespace Modules\Sacraments\Support;

final class SacramentOrdinationType
{
    public const DIACONATE = 'DIACONATE';

    public const PRESBYTERATE = 'PRESBYTERATE';

    public const EPISCOPATE = 'EPISCOPATE';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::DIACONATE, self::PRESBYTERATE, self::EPISCOPATE];
    }

    public static function rule(): string
    {
        return 'in:'.implode(',', self::all());
    }

    public static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $key = strtoupper(str_replace([' ', '-'], '_', trim($value)));

        return in_array($key, self::all(), true) ? $key : null;
    }
}
