<?php

namespace Modules\EcclesiasticalData\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Support\BishopPhotoAuthorization;
use Modules\Tenants\Services\Media\ImageMediaException;
use Modules\Tenants\Services\Media\ImageMediaPolicy;
use Modules\Tenants\Services\Media\ImageMediaService;
use Modules\Tenants\Services\Media\ImageMediaUrlSigner;

class BishopFileUploadService
{
    private const PLATFORM_TENANT_ID = 0;

    public function __construct(
        private readonly ImageMediaService $imageMediaService,
        private readonly ImageMediaUrlSigner $urlSigner,
    ) {}

    public function replacePhoto(BishopManagement $bishop, UploadedFile $file): string
    {
        $previousPath = $bishop->photo_path;

        $result = $this->imageMediaService->replace(
            $file,
            self::PLATFORM_TENANT_ID,
            ImageMediaPolicy::CATEGORY_BISHOPS,
            $previousPath,
            function (string $storageKey) use ($bishop): void {
                DB::transaction(function () use ($bishop, $storageKey): void {
                    $locked = BishopManagement::query()->whereKey($bishop->id)->lockForUpdate()->firstOrFail();
                    $locked->photo_path = $storageKey;
                    $locked->photo_url = null;
                    $locked->save();
                });
            }
        );

        Log::info('Bishop photo stored', [
            'bishop_id' => $bishop->id,
            'uploaded_by' => auth()->id(),
        ]);

        return $result->storageKey;
    }

    public function uploadPhoto(BishopManagement $bishop, UploadedFile $file): string
    {
        return $this->replacePhoto($bishop, $file);
    }

    public function deletePhoto(BishopManagement $bishop): void
    {
        $previousPath = $bishop->photo_path;

        DB::transaction(function () use ($bishop): void {
            $locked = BishopManagement::query()->whereKey($bishop->id)->lockForUpdate()->firstOrFail();
            $locked->photo_path = null;
            $locked->photo_url = null;
            $locked->save();
        });

        $this->imageMediaService->deletePair($previousPath, self::PLATFORM_TENANT_ID);
    }

    public function signedPhotoUrl(?string $photoPath): ?string
    {
        if ($photoPath === null || $photoPath === '') {
            return null;
        }

        return $this->urlSigner->displayUrl(
            $photoPath,
            self::PLATFORM_TENANT_ID,
            fn (): bool => BishopPhotoAuthorization::canView(auth()->user())
        );
    }

    public function resolvePhotoUrl(?string $photoPath, ?string $legacyPhotoUrl = null): ?string
    {
        if ($photoPath !== null && $photoPath !== '') {
            return $this->signedPhotoUrl($photoPath);
        }

        return null;
    }

    public function hasPhoto(?string $photoPath, ?string $legacyPhotoUrl = null): bool
    {
        return $photoPath !== null && $photoPath !== '';
    }

    /**
     * @return array{valid: bool, error: string|null}
     */
    public function validateFile(UploadedFile $file): array
    {
        try {
            (new ImageMediaPolicy)->assertPreUpload($file, ImageMediaPolicy::CATEGORY_BISHOPS);

            $imageInfo = @getimagesize($file->getRealPath());
            if ($imageInfo === false) {
                return [
                    'valid' => false,
                    'error' => 'File is not a valid image',
                ];
            }

            return ['valid' => true, 'error' => null];
        } catch (ImageMediaException $e) {
            return ['valid' => false, 'error' => $e->publicMessage()];
        }
    }

    public function storePendingSuggestionPhoto(string $requestId, UploadedFile $file): string
    {
        $result = $this->imageMediaService->store(
            $file,
            self::PLATFORM_TENANT_ID,
            ImageMediaPolicy::CATEGORY_BISHOPS,
        );

        return $result->storageKey;
    }

    public function applyPendingPhotoToBishop(BishopManagement $bishop, string $pendingPath): string
    {
        if ($this->imageMediaService->checksumForKey($pendingPath) === null) {
            throw new ImageMediaException(
                ImageMediaException::CODE_INVALID_PATH,
                'Suggested bishop photo is no longer available.'
            );
        }

        $previousPath = $bishop->photo_path;

        DB::transaction(function () use ($bishop, $pendingPath): void {
            $locked = BishopManagement::query()->whereKey($bishop->id)->lockForUpdate()->firstOrFail();
            $locked->photo_path = $pendingPath;
            $locked->photo_url = null;
            $locked->save();
        });

        if ($previousPath !== null && $previousPath !== $pendingPath) {
            $this->imageMediaService->deletePair($previousPath, self::PLATFORM_TENANT_ID);
        }

        return $pendingPath;
    }

    public function deleteStoredPath(?string $path): void
    {
        $this->imageMediaService->deletePair($path, self::PLATFORM_TENANT_ID);
    }

    public function uploadCoatOfArms(BishopManagement $bishop, UploadedFile $file): string
    {
        $previousPath = $bishop->coat_of_arms_path;

        $result = $this->imageMediaService->store(
            $file,
            self::PLATFORM_TENANT_ID,
            ImageMediaPolicy::CATEGORY_BISHOPS,
        );

        DB::transaction(function () use ($bishop, $result): void {
            $locked = BishopManagement::query()->whereKey($bishop->id)->lockForUpdate()->firstOrFail();
            $locked->coat_of_arms_path = $result->storageKey;
            $locked->save();
        });

        if ($previousPath !== null && $previousPath !== '' && $previousPath !== $result->storageKey) {
            $this->deleteCoatOfArmsFile($previousPath);
        }

        Log::info('Bishop coat of arms stored', [
            'bishop_id' => $bishop->id,
            'uploaded_by' => auth()->id(),
        ]);

        return $result->storageKey;
    }

    public function deleteCoatOfArms(BishopManagement $bishop): void
    {
        $this->deleteCoatOfArmsFile($bishop->coat_of_arms_path);
        $bishop->update(['coat_of_arms_path' => null]);
    }

    public function publicUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (str_starts_with($path, 'platform/')) {
            return $this->signedPhotoUrl($path);
        }

        return Storage::disk('public')->url($path);
    }

    private function deleteCoatOfArmsFile(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        if (str_starts_with($path, 'platform/')) {
            $this->imageMediaService->deletePair($path, self::PLATFORM_TENANT_ID);

            return;
        }

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
