<?php

namespace Modules\Tenants\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Tenants\Models\ChurchLeadership;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\LeadershipAssignment;
use Modules\Tenants\Models\PopeDetails;
use Modules\Tenants\Services\Media\ImageMediaPolicy;
use Modules\Tenants\Services\Media\ImageMediaService;
use Modules\Tenants\Services\Media\ImageMediaStoreResult;
use Modules\Tenants\Support\ChurchMediaAuthorization;
use Modules\Tenants\Services\Media\ImageMediaUrlSigner;

class ChurchMediaImageService
{
    private const PLATFORM_TENANT_ID = 0;

    public function __construct(
        private readonly ImageMediaService $imageMediaService,
        private readonly ImageMediaUrlSigner $urlSigner,
    ) {}

    public function replacePatronImage(UploadedFile $file, ChurchProfile $profile): ImageMediaStoreResult
    {
        $tenantId = (int) $profile->tenant_id;
        $previous = $profile->patron_image_path;

        return $this->imageMediaService->replace(
            $file,
            $tenantId,
            ImageMediaPolicy::CATEGORY_PATRON,
            $previous,
            function (string $storageKey) use ($profile): void {
                DB::transaction(function () use ($profile, $storageKey): void {
                    $locked = ChurchProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
                    $locked->patron_image_path = $storageKey;
                    $locked->save();
                });
            }
        );
    }

    public function deletePatronImage(ChurchProfile $profile): void
    {
        $tenantId = (int) $profile->tenant_id;
        $previous = $profile->patron_image_path;

        if ($previous === null) {
            return;
        }

        DB::transaction(function () use ($profile): void {
            $locked = ChurchProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $locked->patron_image_path = null;
            $locked->save();
        });

        $this->imageMediaService->deletePair($previous, $tenantId);
    }

    public function patronImageUrl(?string $storageKey, int $tenantId): ?string
    {
        return $this->urlSigner->displayUrl(
            $storageKey,
            $tenantId,
            fn (): bool => ChurchMediaAuthorization::canViewTenantMedia($tenantId, auth()->user())
        );
    }

    public function replaceAssignmentPhoto(UploadedFile $file, LeadershipAssignment $assignment): ImageMediaStoreResult
    {
        $tenantId = (int) $assignment->tenant_id;
        $previous = $assignment->photo_url;

        return $this->imageMediaService->replace(
            $file,
            $tenantId,
            ImageMediaPolicy::CATEGORY_LEADERSHIP,
            $previous,
            function (string $storageKey) use ($assignment): void {
                DB::transaction(function () use ($assignment, $storageKey): void {
                    $locked = LeadershipAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
                    $locked->photo_url = $storageKey;
                    $locked->updated_by = auth()->id();
                    $locked->save();
                });
            }
        );
    }

    public function deleteAssignmentPhoto(LeadershipAssignment $assignment): void
    {
        $tenantId = (int) $assignment->tenant_id;
        $previous = $assignment->photo_url;

        if ($previous === null || $previous === '') {
            return;
        }

        if (str_starts_with($previous, 'http://') || str_starts_with($previous, 'https://')) {
            DB::transaction(function () use ($assignment): void {
                $locked = LeadershipAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
                $locked->photo_url = null;
                $locked->save();
            });

            return;
        }

        DB::transaction(function () use ($assignment): void {
            $locked = LeadershipAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $locked->photo_url = null;
            $locked->save();
        });

        $this->imageMediaService->deletePair($previous, $tenantId);
    }

    public function replaceLeadershipPhoto(UploadedFile $file, ChurchLeadership $leader): ImageMediaStoreResult
    {
        $tenantId = (int) $leader->tenant_id;
        $previous = $leader->photo_url;

        return $this->imageMediaService->replace(
            $file,
            $tenantId,
            ImageMediaPolicy::CATEGORY_LEADERSHIP,
            $previous,
            function (string $storageKey) use ($leader): void {
                DB::transaction(function () use ($leader, $storageKey): void {
                    $locked = ChurchLeadership::query()->whereKey($leader->id)->lockForUpdate()->firstOrFail();
                    $locked->photo_url = $storageKey;
                    $locked->save();
                });
            }
        );
    }

    public function deleteLeadershipPhoto(ChurchLeadership $leader): void
    {
        $tenantId = (int) $leader->tenant_id;
        $previous = $leader->photo_url;

        if ($previous === null || str_starts_with($previous, 'http://') || str_starts_with($previous, 'https://')) {
            DB::transaction(function () use ($leader): void {
                $locked = ChurchLeadership::query()->whereKey($leader->id)->lockForUpdate()->firstOrFail();
                $locked->photo_url = null;
                $locked->save();
            });

            return;
        }

        DB::transaction(function () use ($leader): void {
            $locked = ChurchLeadership::query()->whereKey($leader->id)->lockForUpdate()->firstOrFail();
            $locked->photo_url = null;
            $locked->save();
        });

        $this->imageMediaService->deletePair($previous, $tenantId);
    }

    public function leadershipPhotoUrl(?string $storageKey, int $tenantId): ?string
    {
        if ($storageKey === null || $storageKey === '') {
            return null;
        }

        if (str_starts_with($storageKey, 'http://') || str_starts_with($storageKey, 'https://')) {
            return null;
        }

        return $this->urlSigner->displayUrl(
            $storageKey,
            $tenantId,
            fn (): bool => ChurchMediaAuthorization::canViewTenantMedia($tenantId, auth()->user())
        );
    }

    public function replacePopeImage(UploadedFile $file, PopeDetails $popeDetails): ImageMediaStoreResult
    {
        $previous = $popeDetails->pope_image_path;

        return $this->imageMediaService->replace(
            $file,
            self::PLATFORM_TENANT_ID,
            ImageMediaPolicy::CATEGORY_POPE,
            $previous,
            function (string $storageKey) use ($popeDetails): void {
                DB::transaction(function () use ($popeDetails, $storageKey): void {
                    $locked = PopeDetails::query()->whereKey($popeDetails->id)->lockForUpdate()->firstOrFail();
                    $locked->pope_image_path = $storageKey;
                    $locked->save();
                });
            }
        );
    }

    public function deletePopeImage(PopeDetails $popeDetails): void
    {
        $previous = $popeDetails->pope_image_path;

        if ($previous === null) {
            return;
        }

        DB::transaction(function () use ($popeDetails): void {
            $locked = PopeDetails::query()->whereKey($popeDetails->id)->lockForUpdate()->firstOrFail();
            $locked->pope_image_path = null;
            $locked->save();
        });

        $this->imageMediaService->deletePair($previous, self::PLATFORM_TENANT_ID);
    }

    public function popeImageUrl(?string $storageKey): ?string
    {
        return $this->urlSigner->displayUrl(
            $storageKey,
            self::PLATFORM_TENANT_ID,
            fn (): bool => ChurchMediaAuthorization::canViewPopeMedia(auth()->user())
        );
    }
}
