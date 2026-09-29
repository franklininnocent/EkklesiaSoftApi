<?php

namespace Modules\SupportTickets\Support;

final class TicketStatus
{
    public const DRAFT = 'draft';

    public const NEW = 'new';

    public const IN_PROGRESS = 'in_progress';

    public const AWAITING_YOU = 'awaiting_you';

    public const AWAITING_EKKLESIA = 'awaiting_ekklesia';

    public const RESOLVED = 'resolved';

    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::DRAFT,
            self::NEW,
            self::IN_PROGRESS,
            self::AWAITING_YOU,
            self::AWAITING_EKKLESIA,
            self::RESOLVED,
            self::CLOSED,
            self::CANCELLED,
        ];
    }

    /** @return list<string> */
    public static function open(): array
    {
        return [
            self::DRAFT,
            self::NEW,
            self::IN_PROGRESS,
            self::AWAITING_YOU,
            self::AWAITING_EKKLESIA,
        ];
    }

    /** @return list<string> */
    public static function cancellable(): array
    {
        return [
            self::DRAFT,
            self::NEW,
            self::IN_PROGRESS,
            self::AWAITING_YOU,
            self::AWAITING_EKKLESIA,
        ];
    }

    /** @return list<string> */
    public static function tenantResolvable(): array
    {
        return [
            self::NEW,
            self::IN_PROGRESS,
            self::AWAITING_YOU,
            self::AWAITING_EKKLESIA,
        ];
    }
}
