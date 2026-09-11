<?php

namespace Modules\Tenants\Services\Media;

use Modules\Tenants\Support\TenantPrivateStorage;

final class ImageMediaPaths
{
    public static function displayKey(int $tenantId, string $category, string $uuid): string
    {
        return self::key($tenantId, $category, $uuid, false);
    }

    public static function thumbKey(int $tenantId, string $category, string $uuid): string
    {
        return self::key($tenantId, $category, $uuid, true);
    }

    public static function thumbKeyForDisplayKey(string $displayKey): string
    {
        if (str_ends_with($displayKey, '.webp')) {
            return preg_replace('/\.webp$/', '-thumb.webp', $displayKey) ?? $displayKey.'-thumb.webp';
        }

        return $displayKey.'-thumb.webp';
    }

    public static function tenantOwnsKey(string $storageKey, int $tenantId): bool
    {
        if (str_starts_with($storageKey, 'platform/')) {
            return true;
        }

        return (bool) preg_match('#^tenants/'.$tenantId.'/#', $storageKey);
    }

    private static function key(int $tenantId, string $category, string $uuid, bool $thumb): string
    {
        $filename = $thumb ? "{$uuid}-thumb.webp" : "{$uuid}.webp";

        if (in_array($category, ImageMediaPolicy::PLATFORM_CATEGORIES, true)) {
            return 'platform/'.$category.'/'.$filename;
        }

        return TenantPrivateStorage::relativePath($tenantId, $category, $filename);
    }
}
