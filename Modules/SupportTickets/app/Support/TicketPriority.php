<?php

namespace Modules\SupportTickets\Support;

final class TicketPriority
{
    public const LOW = 'low';

    public const NORMAL = 'normal';

    public const HIGH = 'high';

    public const URGENT = 'urgent';

    public const CRITICAL = 'critical';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::LOW,
            self::NORMAL,
            self::HIGH,
            self::URGENT,
            self::CRITICAL,
        ];
    }
}
