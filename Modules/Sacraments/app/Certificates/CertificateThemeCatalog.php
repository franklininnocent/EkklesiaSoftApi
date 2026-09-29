<?php

namespace Modules\Sacraments\Certificates;

final class CertificateThemeCatalog
{
    public const CANONICAL_EMBLEM = 'CHI_RHO';

    /**
     * @return array<string, string>
     */
    public static function tokens(string $themeId): array
    {
        return match ($themeId) {
            'catholic' => [
                'pageBackground' => '#fbf7ef',
                'ink' => '#1f1a14',
                'mutedInk' => '#5c5346',
                'accent' => '#6b2d3c',
                'borderOuter' => '#6b2d3c',
                'borderInner' => '#c4a574',
                'microprint' => 'IN NOMINE PATRIS',
            ],
            'csi' => [
                'pageBackground' => '#f7f5f0',
                'ink' => '#1c2418',
                'mutedInk' => '#4d5648',
                'accent' => '#2f4f3e',
                'borderOuter' => '#2f4f3e',
                'borderInner' => '#8a9a7a',
                'microprint' => 'CHURCH OF SOUTH INDIA',
            ],
            default => [
                'pageBackground' => '#ffffff',
                'ink' => '#1a1a1a',
                'mutedInk' => '#4b5563',
                'accent' => '#1e3a5f',
                'borderOuter' => '#1e3a5f',
                'borderInner' => '#94a3b8',
                'microprint' => 'CHURCH REGISTER',
            ],
        };
    }

    public static function emblemFor(string $themeId, string $sacramentType): string
    {
        return self::CANONICAL_EMBLEM;
    }
}
