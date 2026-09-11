<?php

namespace Modules\Family\app\Services;

use Illuminate\Http\UploadedFile;
use Modules\Tenants\Services\Media\ImageMediaException;
use Modules\Tenants\Services\Media\ImageMediaPolicy;
use Modules\Tenants\Services\Media\ImageMediaService;
use Modules\Tenants\Services\Media\ImageMediaStoreResult;

class FamilyFileUploadService
{
    public function __construct(
        private readonly ImageMediaService $imageMediaService,
    ) {}

    public function store(UploadedFile $file, int $tenantId): ImageMediaStoreResult
    {
        return $this->imageMediaService->store($file, $tenantId, ImageMediaPolicy::CATEGORY_FAMILIES);
    }

    /**
     * @param  callable(string): void  $commitNewKey
     */
    public function replace(
        UploadedFile $file,
        int $tenantId,
        ?string $previousStorageKey,
        callable $commitNewKey,
    ): ImageMediaStoreResult {
        return $this->imageMediaService->replace(
            $file,
            $tenantId,
            ImageMediaPolicy::CATEGORY_FAMILIES,
            $previousStorageKey,
            $commitNewKey,
        );
    }

    public function deletePair(?string $storageKey, int $tenantId): void
    {
        $this->imageMediaService->deletePair($storageKey, $tenantId);
    }

    /**
     * @return array{valid: bool, error: string|null}
     */
    public function validateFile(UploadedFile $file): array
    {
        try {
            (new ImageMediaPolicy)->assertPreUpload($file, ImageMediaPolicy::CATEGORY_FAMILIES);

            return ['valid' => true, 'error' => null];
        } catch (ImageMediaException $e) {
            return ['valid' => false, 'error' => $e->publicMessage()];
        }
    }
}
