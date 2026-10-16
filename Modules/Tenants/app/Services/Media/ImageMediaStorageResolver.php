<?php

namespace Modules\Tenants\Services\Media;

use Illuminate\Support\Facades\Storage;
use Modules\Tenants\Support\TenantPrivateStorage;

final class ImageMediaStorageResolver
{
    /**
     * @return array{disk: string, path: string}|null
     */
    public function resolve(string $storageKey): ?array
    {
        $normalized = TenantPrivateStorage::normalize($storageKey);
        if ($normalized !== null && TenantPrivateStorage::exists($normalized)) {
            return [
                'disk' => (string) config('tenants.storage.private_disk', 'local'),
                'path' => $normalized,
            ];
        }

        if (! config('tenants.media.dual_read_public_fallback', true)) {
            return null;
        }

        $publicPath = TenantPrivateStorage::normalize($storageKey);
        if ($publicPath !== null && Storage::disk('public')->exists($publicPath)) {
            return [
                'disk' => 'public',
                'path' => $publicPath,
            ];
        }

        return null;
    }

    public function resolveForVariant(string $displayStorageKey, string $variant): ?array
    {
        $path = $variant === ImageMediaToken::VARIANT_THUMB
            ? ImageMediaPaths::thumbKeyForDisplayKey($displayStorageKey)
            : $displayStorageKey;

        return $this->resolve($path);
    }
}
