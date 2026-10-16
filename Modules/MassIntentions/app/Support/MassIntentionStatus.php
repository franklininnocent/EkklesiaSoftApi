<?php

namespace Modules\MassIntentions\Support;

final class MassIntentionStatus
{
    public const OPEN = 'open';

    public const CLOSED = 'closed';

    /** @deprecated Legacy values; migrated to open/closed */
    public const DRAFT = 'draft';

    public const PENDING_REVIEW = 'pending_review';

    public const AWAITING_CLARIFICATION = 'awaiting_clarification';

    public const ACCEPTED = 'accepted';

    public const WITHDRAWN = 'withdrawn';

    public static function isOpen(string $status): bool
    {
        return $status === self::OPEN;
    }

    public static function isClosed(string $status): bool
    {
        return $status === self::CLOSED;
    }

    /**
     * @return list<string>
     */
    public static function legacyOpenStatuses(): array
    {
        return [
            self::DRAFT,
            self::PENDING_REVIEW,
            self::AWAITING_CLARIFICATION,
            self::ACCEPTED,
        ];
    }
}
