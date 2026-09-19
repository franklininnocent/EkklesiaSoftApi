<?php

namespace Modules\Tenants\Support;

final class LeadershipRoleNameNormalizer
{
    /**
     * Canonical display title: trim and collapse internal whitespace.
     */
    public static function canonicalize(string $title): string
    {
        $trimmed = trim($title);

        if ($trimmed === '') {
            return '';
        }

        return (string) preg_replace('/\s+/u', ' ', $trimmed);
    }

    /**
     * Normalized comparison key (case-insensitive, whitespace-collapsed).
     */
    public static function normalize(string $title): string
    {
        $canonical = self::canonicalize($title);

        if ($canonical === '') {
            return '';
        }

        return mb_strtolower($canonical, 'UTF-8');
    }
}
