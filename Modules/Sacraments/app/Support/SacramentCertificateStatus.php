<?php

namespace Modules\Sacraments\Support;

/**
 * Certificate issuance statuses (ADR-09 / ADR-10).
 */
final class SacramentCertificateStatus
{
    public const DRAFT_PREVIEW = 'draft_preview';

    public const ISSUED = 'issued';

    public const SUPERSEDED = 'superseded';

    public const VOIDED = 'voided';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::DRAFT_PREVIEW,
            self::ISSUED,
            self::SUPERSEDED,
            self::VOIDED,
        ];
    }

    public static function rule(): string
    {
        return 'in:'.implode(',', self::all());
    }
}
