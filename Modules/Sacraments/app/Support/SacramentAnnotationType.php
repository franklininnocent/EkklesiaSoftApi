<?php

namespace Modules\Sacraments\Support;

/**
 * Canonical annotations (Can. 1122–1123). Register-only history; never on the default certificate.
 */
final class SacramentAnnotationType
{
    public const BAPTISMAL_REGISTER_NOTATION = 'baptismal_register_notation';

    public const CONVALIDATION = 'convalidation';

    public const DECLARATION_OF_NULLITY = 'declaration_of_nullity';

    public const LEGITIMATE_DISSOLUTION = 'legitimate_dissolution';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::BAPTISMAL_REGISTER_NOTATION,
            self::CONVALIDATION,
            self::DECLARATION_OF_NULLITY,
            self::LEGITIMATE_DISSOLUTION,
        ];
    }

    public static function rule(): string
    {
        return 'in:'.implode(',', self::all());
    }

    public static function label(?string $value): ?string
    {
        return match ($value) {
            self::BAPTISMAL_REGISTER_NOTATION => 'Noted in baptismal register',
            self::CONVALIDATION => 'Convalidation',
            self::DECLARATION_OF_NULLITY => 'Declaration of nullity',
            self::LEGITIMATE_DISSOLUTION => 'Legitimate dissolution',
            default => null,
        };
    }
}
