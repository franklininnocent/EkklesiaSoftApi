<?php

namespace Modules\MassIntentions\Support;

final class MassScheduleConstants
{
    public const MAX_SLOTS_PER_WEEKDAY = 8;

    public const MAX_SLOTS_PER_REVISION = 24;

    public const MAX_OVERRIDE_SLOTS = 12;

    public const MAX_APPLY_RANGE_DAYS = 400;

    public const MAX_LIST_RANGE_DAYS = 92;

    public const DEFAULT_LIST_RANGE_DAYS = 31;

    public const GENERATION_HORIZON_DAYS = 180;

    /** Ops alert when cursor is behind parish today + this many days (plan §10). */
    public const GENERATION_ATTENTION_MIN_DAYS_AHEAD = 90;

    public const WEEKDAY_SUNDAY = 0;

    public const WEEKDAY_SATURDAY = 6;
}
