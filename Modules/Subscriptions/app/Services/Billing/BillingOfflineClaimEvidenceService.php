<?php

namespace Modules\Subscriptions\Services\Billing;

use Illuminate\Http\UploadedFile;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\BillingOfflineClaim;
use Modules\Tenants\Services\Media\ImageMediaPolicy;
use Modules\Tenants\Services\Media\ImageMediaService;

final class BillingOfflineClaimEvidenceService
{
    public function __construct(private readonly ImageMediaService $media) {}

    public function attach(BillingOfflineClaim $claim, User $user, UploadedFile $file): BillingOfflineClaim
    {
        if ((int) $claim->submitted_by_user_id !== (int) $user->id) {
            throw SubscriptionException::billingDenied('forbidden', 'You cannot modify this claim.');
        }

        if (! in_array($claim->status, [BillingOfflineClaim::STATUS_PENDING, BillingOfflineClaim::STATUS_INFO_REQUIRED], true)) {
            throw SubscriptionException::invalidState('Evidence cannot be uploaded for this claim.');
        }

        $tenantId = (int) $claim->tenant_id;
        $previous = $claim->evidence_storage_path;
        if ($previous === null || $previous === '') {
            $result = $this->media->store($file, $tenantId, ImageMediaPolicy::CATEGORY_BILLING_EVIDENCE);
            $claim->forceFill(['evidence_storage_path' => $result->storageKey])->save();
        } else {
            $this->media->replace(
                $file,
                $tenantId,
                ImageMediaPolicy::CATEGORY_BILLING_EVIDENCE,
                $previous,
                function (string $newKey) use ($claim): void {
                    $claim->forceFill(['evidence_storage_path' => $newKey])->save();
                },
            );
        }

        return $claim->fresh();
    }
}
