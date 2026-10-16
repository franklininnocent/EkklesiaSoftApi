<?php

namespace Modules\Authentication\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Authentication\Models\User;
use Modules\Tenants\Services\Media\ImageMediaException;
use Modules\Tenants\Services\Media\ImageMediaPolicy;
use Modules\Tenants\Services\Media\ImageMediaService;

class UserProfileImageService
{
    public function __construct(
        private readonly ImageMediaService $imageMediaService,
    ) {}

    public function replaceProfileImage(UploadedFile $file, User $user): string
    {
        if (! $user->tenant_id) {
            throw new ImageMediaException(
                ImageMediaException::CODE_INVALID_PATH,
                'User is not associated with a tenant.'
            );
        }

        $tenantId = (int) $user->tenant_id;
        $previousPath = $user->profile_image_path;

        $result = $this->imageMediaService->replace(
            $file,
            $tenantId,
            ImageMediaPolicy::CATEGORY_USERS,
            $previousPath,
            function (string $storageKey) use ($user): void {
                DB::transaction(function () use ($user, $storageKey): void {
                    $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                    $locked->profile_image_path = $storageKey;
                    $locked->save();
                });
            }
        );

        Log::info('User profile image stored', [
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'uploaded_by' => auth()->id(),
        ]);

        return $result->storageKey;
    }

    public function deleteProfileImage(string $path, int $tenantId): bool
    {
        try {
            $this->imageMediaService->deletePair($path, $tenantId);

            return true;
        } catch (\Throwable $e) {
            Log::error('User profile image deletion failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array{valid: bool, error: string|null}
     */
    public function validateFile(UploadedFile $file): array
    {
        try {
            (new ImageMediaPolicy)->assertPreUpload($file, ImageMediaPolicy::CATEGORY_USERS);

            return ['valid' => true, 'error' => null];
        } catch (ImageMediaException $e) {
            return ['valid' => false, 'error' => $e->publicMessage()];
        }
    }
}
