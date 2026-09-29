<?php

namespace Modules\Sacraments\Support;

/**
 * Migration resolution / confidence values (ADR-11).
 */
final class SacramentMigrationResolutionStatus
{
    public const MEMBER = 'member';

    public const EXTERNAL = 'external';

    public const UNRESOLVED = 'unresolved';

    public const CONFIDENCE_EXACT = 'exact';

    public const CONFIDENCE_AMBIGUOUS = 'ambiguous';

    public const CONFIDENCE_NONE = 'none';
}
