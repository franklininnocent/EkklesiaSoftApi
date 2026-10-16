<?php

namespace Modules\Sacraments\Support;

final class SacramentConflictSeverity
{
    public const INFO = 'INFO';

    public const WARNING = 'WARNING';

    public const REVIEW_REQUIRED = 'REVIEW_REQUIRED';

    public const BLOCKING = 'BLOCKING';

    public static function blocksSave(string $severity): bool
    {
        return in_array($severity, [self::REVIEW_REQUIRED, self::BLOCKING], true);
    }
}
