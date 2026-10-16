<?php

namespace Modules\Tenants\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Tenant-scoped private disk paths under tenants/{tenantId}/….
 */
final class TenantPrivateStorage
{
    public static function disk(?string $diskName = null): Filesystem
    {
        return Storage::disk($diskName ?? (string) config('tenants.storage.private_disk', 'local'));
    }

    public static function relativePath(int $tenantId, string $category, string $filename): string
    {
        $tenantId = max(0, $tenantId);
        $category = trim(str_replace(['..', '\\'], '', $category), '/');
        $filename = ltrim(str_replace(['..', '\\'], '', $filename), '/');

        return sprintf('tenants/%d/%s/%s', $tenantId, $category, $filename);
    }

    public static function exists(string $relativePath, ?string $diskName = null): bool
    {
        $normalized = self::normalize($relativePath);

        return $normalized !== null && self::disk($diskName)->exists($normalized);
    }

    public static function get(string $relativePath, ?string $diskName = null): string
    {
        $normalized = self::normalize($relativePath);
        if ($normalized === null) {
            throw new \RuntimeException('Invalid storage path.');
        }

        $disk = self::disk($diskName);
        if (! $disk->exists($normalized)) {
            throw new \RuntimeException('File not found.');
        }

        return (string) $disk->get($normalized);
    }

    public static function put(string $relativePath, string $contents, ?string $diskName = null): bool
    {
        $normalized = self::normalize($relativePath);
        if ($normalized === null) {
            return false;
        }

        return self::disk($diskName)->put($normalized, $contents);
    }

    public static function normalize(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        return ltrim($path, '/');
    }
}
