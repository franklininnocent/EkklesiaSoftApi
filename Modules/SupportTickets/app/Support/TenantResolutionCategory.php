<?php

namespace Modules\SupportTickets\Support;

final class TenantResolutionCategory
{
    public const SELF_RESOLVED = 'self_resolved';

    public const NO_LONGER_NEEDED = 'no_longer_needed';

    public const ACTION_COMPLETED = 'action_completed';

    public const EXPLICIT_RESOLUTION = 'explicit_resolution';

    public const EKKLESIA_RESOLVED = 'ekklesia_resolved';

    public static function forStatus(string $status): string
    {
        return match ($status) {
            TicketStatus::NEW => self::SELF_RESOLVED,
            TicketStatus::AWAITING_EKKLESIA => self::NO_LONGER_NEEDED,
            TicketStatus::AWAITING_YOU => self::ACTION_COMPLETED,
            TicketStatus::IN_PROGRESS => self::EXPLICIT_RESOLUTION,
            default => self::EXPLICIT_RESOLUTION,
        };
    }

    public static function requiresSummary(string $status): bool
    {
        return $status === TicketStatus::IN_PROGRESS;
    }

    public static function label(string $category): string
    {
        return match ($category) {
            self::SELF_RESOLVED => 'Solved without support',
            self::NO_LONGER_NEEDED => 'No longer needed',
            self::ACTION_COMPLETED => 'Requested action completed',
            self::EXPLICIT_RESOLUTION => 'Resolved with explanation',
            self::EKKLESIA_RESOLVED => 'Resolved by Ekklesia support',
            default => ucfirst(str_replace('_', ' ', $category)),
        };
    }
}
