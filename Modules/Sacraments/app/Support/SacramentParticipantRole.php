<?php

namespace Modules\Sacraments\Support;

/**
 * Controlled participant roles (ADR-01). Holy Orders extras reserved for Phase 10.
 */
final class SacramentParticipantRole
{
    public const RECIPIENT = 'recipient';

    public const BRIDE = 'bride';

    public const GROOM = 'groom';

    public const FATHER = 'father';

    public const MOTHER = 'mother';

    public const GODFATHER = 'godfather';

    public const GODMOTHER = 'godmother';

    public const SPONSOR = 'sponsor';

    public const WITNESS = 'witness';

    public const MINISTER = 'minister';

    public const CANDIDATE = 'candidate';

    public const CO_CONSECRATOR = 'co_consecrator';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::RECIPIENT,
            self::BRIDE,
            self::GROOM,
            self::FATHER,
            self::MOTHER,
            self::GODFATHER,
            self::GODMOTHER,
            self::SPONSOR,
            self::WITNESS,
            self::MINISTER,
            self::CANDIDATE,
            self::CO_CONSECRATOR,
        ];
    }

    public static function rule(): string
    {
        return 'in:'.implode(',', self::all());
    }
}
