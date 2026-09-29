<?php

namespace Modules\Sacraments\Support;

/**
 * Participant identity sources (ADR-01 / ADR-03 / ADR-04).
 */
final class SacramentParticipantSource
{
    public const MEMBER = 'member';

    public const PERSON = 'person';

    public const INTERNAL_LEADERSHIP = 'internal_leadership';

    public const EXTERNAL = 'external';

    public const UNRESOLVED = 'unresolved';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::MEMBER,
            self::PERSON,
            self::INTERNAL_LEADERSHIP,
            self::EXTERNAL,
            self::UNRESOLVED,
        ];
    }

    /**
     * Sources allowed on normal create API (not migration).
     *
     * @return list<string>
     */
    public static function creatable(): array
    {
        return [
            self::MEMBER,
            self::PERSON,
            self::INTERNAL_LEADERSHIP,
            self::EXTERNAL,
        ];
    }

    public static function rule(): string
    {
        return 'in:'.implode(',', self::all());
    }
}
