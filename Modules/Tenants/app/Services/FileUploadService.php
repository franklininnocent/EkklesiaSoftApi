<?php

namespace Modules\Tenants\Services;

use Illuminate\Http\UploadedFile;
use Modules\Authentication\Models\User;
use Modules\Tenants\Services\Media\ImageMediaException;
use Modules\Tenants\Services\Media\ImageMediaPolicy;
use Modules\Tenants\Services\Media\ImageMediaService;
use Modules\Tenants\Services\Media\ImageMediaStoreResult;
use Modules\Tenants\Services\Media\ImageMediaUrlSigner;
use Modules\Tenants\Support\TenantLogoAuthorization;

class FileUploadService
{
    public function __construct(
        private readonly ImageMediaService $imageMediaService,
        private readonly ImageMediaUrlSigner $urlSigner,
    ) {}

    /**
     * @param  callable(string): void  $commitNewKey
     */
    public function replaceTenantLogo(
        UploadedFile $file,
        int $tenantId,
        ?string $previousStorageKey,
        callable $commitNewKey,
    ): ImageMediaStoreResult {
        return $this->imageMediaService->replace(
            $file,
            $tenantId,
            ImageMediaPolicy::CATEGORY_LOGOS,
            $previousStorageKey,
            $commitNewKey,
        );
    }

    public function storeTenantLogo(UploadedFile $file, int $tenantId): ImageMediaStoreResult
    {
        return $this->imageMediaService->store($file, $tenantId, ImageMediaPolicy::CATEGORY_LOGOS);
    }

    public function deleteTenantLogo(?string $storageKey, int $tenantId): void
    {
        $this->imageMediaService->deletePair($storageKey, $tenantId);
    }

    public function getTenantLogoUrl(?string $storageKey, int $tenantId, ?User $viewer = null): ?string
    {
        if ($storageKey === null || $storageKey === '') {
            return null;
        }

        return $this->urlSigner->displayUrl(
            $storageKey,
            $tenantId,
            fn (): bool => TenantLogoAuthorization::canView($tenantId, $viewer ?? auth()->user())
        );
    }

    /**
     * @return array{valid: bool, error: string|null}
     */
    public function validateFile(UploadedFile $file): array
    {
        try {
            (new ImageMediaPolicy)->assertPreUpload($file, ImageMediaPolicy::CATEGORY_LOGOS);

            return ['valid' => true, 'error' => null];
        } catch (ImageMediaException $e) {
            return ['valid' => false, 'error' => $e->publicMessage()];
        }
    }

    /**
     * Get file size in human-readable format.
     */
    public function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
