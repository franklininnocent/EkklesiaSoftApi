<?php

namespace Modules\Sacraments\Support;

final class SacramentDispensationType
{
    public const MIXED_MARRIAGE_PERMISSION = 'mixed_marriage_permission';

    public const DISPARITY_OF_CULT = 'disparity_of_cult';

    public const OTHER = 'other';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::MIXED_MARRIAGE_PERMISSION,
            self::DISPARITY_OF_CULT,
            self::OTHER,
        ];
    }

    public static function rule(): string
    {
        return 'in:'.implode(',', self::all());
    }
}
