<?php

namespace Modules\SupportTickets\Support;

final class ResolvedByActor
{
    public const TENANT = 'tenant';

    public const EKKLESIA = 'ekklesia';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::TENANT,
            self::EKKLESIA,
        ];
    }
}
