<?php

namespace Modules\Donations\Support;

final class ContributionPlanFrequencies
{
    public const ALL = [
        'one_time',
        'weekly',
        'monthly',
        'quarterly',
        'half_yearly',
        'yearly',
        'custom',
    ];

    public const VALIDATION_RULE = 'in:one_time,weekly,monthly,quarterly,half_yearly,yearly,custom';

    public const MAX_PERIODS_PER_RUN = 520;

    public const MAX_CUSTOM_INTERVAL_DAYS = 366;

    public const ASYNC_THRESHOLD = 2000;
}
