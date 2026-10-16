<?php

namespace Modules\Tenants\Database\Seeders\Support;

/**
 * Markers and limits for the compact parish demo dataset (5 BCCs × 5 families).
 */
final class CompactDemoMarkers
{
    public const MARKER = 'compact_demo_v1';

    /** @var list<string> */
    public const BCC_CODES = ['DEMO-N', 'DEMO-S', 'DEMO-E', 'DEMO-W', 'DEMO-C'];

    public const FAMILIES_PER_BCC = 5;

    /** @var list<int> Deterministic household sizes per slot index 0–4 within each BCC. */
    public const HOUSEHOLD_SIZES = [2, 3, 4, 5, 5];

    public const MAX_MEMBERS = 100;

    public const SACRAMENT_REGISTER_TARGET = 48;

    public const MASS_INTENTION_TARGET = 6;

    public const ENV_ONLY_DEMO_BCCS = 'BCC_DUMMY_ONLY_DEMO_BCCS';

    public const ENV_COMPACT = 'BCC_DUMMY_COMPACT';

    public const LOCK_PREFIX = 'tenant-demo-seed:';

    public const LOCK_SECONDS = 900;
}
