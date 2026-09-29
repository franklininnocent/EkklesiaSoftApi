<?php

namespace Modules\Sacraments\Support;

final class SacramentRecordStatus
{
    public const FOUND = 'FOUND';

    public const NOT_FOUND = 'NOT_FOUND';

    public const NOT_SEARCHED = 'NOT_SEARCHED';

    public const NOT_APPLICABLE = 'NOT_APPLICABLE';

    public const ACCESS_DENIED = 'ACCESS_DENIED';

    public const ERROR = 'ERROR';

    public const MULTIPLE_CANDIDATES = 'MULTIPLE_CANDIDATES';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::FOUND,
            self::NOT_FOUND,
            self::NOT_SEARCHED,
            self::NOT_APPLICABLE,
            self::ACCESS_DENIED,
            self::ERROR,
            self::MULTIPLE_CANDIDATES,
        ];
    }
}
