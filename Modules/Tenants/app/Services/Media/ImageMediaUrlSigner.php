<?php

namespace Modules\Tenants\Services\Media;

use Illuminate\Support\Facades\URL;

final class ImageMediaUrlSigner
{
    public function thumbUrl(?string $displayStorageKey, int $tenantId, callable $authorize): ?string
    {
        return $this->signedUrl($displayStorageKey, $tenantId, ImageMediaToken::VARIANT_THUMB, $authorize);
    }

    public function displayUrl(?string $displayStorageKey, int $tenantId, callable $authorize): ?string
    {
        return $this->signedUrl($displayStorageKey, $tenantId, ImageMediaToken::VARIANT_DISPLAY, $authorize);
    }

    private function signedUrl(?string $displayStorageKey, int $tenantId, string $variant, callable $authorize): ?string
    {
        if ($displayStorageKey === null || $displayStorageKey === '') {
            return null;
        }

        if (! $authorize()) {
            return null;
        }

        $ttlMinutes = $variant === ImageMediaToken::VARIANT_THUMB
            ? (int) config('tenants.media.ttl.thumb_minutes', 10)
            : (int) config('tenants.media.ttl.display_minutes', 15);

        $expiresAt = now()->addMinutes($ttlMinutes);
        $token = ImageMediaToken::encode(
            tenantId: $tenantId,
            storageKey: $displayStorageKey,
            variant: $variant,
            expiresAt: $expiresAt->timestamp,
        );

        return URL::temporarySignedRoute(
            'api.tenants.media.serve',
            $expiresAt,
            ['token' => $token]
        );
    }
}
