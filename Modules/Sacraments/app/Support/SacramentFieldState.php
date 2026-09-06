<?php

namespace Modules\Sacraments\Support;

final class SacramentFieldState
{
    public const CANONICAL = 'CANONICAL';

    public const READ_ONLY_VERIFIED = 'READ_ONLY_VERIFIED';

    public const DERIVED = 'DERIVED';

    public const EDITABLE = 'EDITABLE';

    public const USER_ENTERED = 'USER_ENTERED';

    public const MISSING = 'MISSING';

    public const CONFLICT = 'CONFLICT';

    public const MULTIPLE_CANDIDATES = 'MULTIPLE_CANDIDATES';
}
