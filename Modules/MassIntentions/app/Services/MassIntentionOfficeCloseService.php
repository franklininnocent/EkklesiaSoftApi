<?php

namespace Modules\MassIntentions\Services;

use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Support\MassIntentionCloseSource;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Illuminate\Validation\ValidationException;

class MassIntentionOfficeCloseService
{
    public function __construct(
        private readonly MassIntentionAuditService $audits,
    ) {
    }

    /**
     * Batch-close open intentions whose scheduled date is before today in church TZ.
     */
    public function closeExpiredForTenant(int $tenantId): int
    {
        $today = DonationBusinessDate::today($tenantId);

        $ids = MassIntentionRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('status', MassIntentionStatus::OPEN)
            ->whereNotNull('requested_date')
            ->whereDate('requested_date', '<', $today)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        $closedAt = now();

        $updated = MassIntentionRequest::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->update([
                'status' => MassIntentionStatus::CLOSED,
                'closed_at' => $closedAt,
                'closed_by_user_id' => null,
                'close_source' => MassIntentionCloseSource::AUTOMATIC,
            ]);

        foreach ($ids as $requestId) {
            $this->audits->record($tenantId, 'request.closed', null, $requestId, null, [
                'close_source' => MassIntentionCloseSource::AUTOMATIC,
            ]);
        }

        return (int) $updated;
    }

    public function closeExpiredForAllTenants(): int
    {
        $total = 0;
        $tenantIds = MassIntentionRequest::query()
            ->where('status', MassIntentionStatus::OPEN)
            ->whereNotNull('requested_date')
            ->distinct()
            ->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $total += $this->closeExpiredForTenant((int) $tenantId);
        }

        return $total;
    }

    public function closeManual(int $tenantId, User $actor, string $requestId): MassIntentionRequest
    {
        return DB::transaction(function () use ($tenantId, $actor, $requestId): MassIntentionRequest {
            $request = MassIntentionRequest::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $requestId)
                ->lockForUpdate()
                ->firstOrFail();

            if (MassIntentionStatus::isClosed($request->status)) {
                return $request;
            }

            if (! MassIntentionStatus::isOpen($request->status)) {
                throw ValidationException::withMessages([
                    'status' => 'This intention cannot be closed in its current state.',
                ]);
            }

            $request->status = MassIntentionStatus::CLOSED;
            $request->closed_at = now();
            $request->closed_by_user_id = $actor->id;
            $request->close_source = MassIntentionCloseSource::MANUAL;
            $request->save();

            $this->audits->record($tenantId, 'request.closed', $actor, $request->id, null, [
                'close_source' => MassIntentionCloseSource::MANUAL,
            ]);

            return $request->fresh();
        });
    }

    public function shouldAutoCloseAfterSave(int $tenantId, MassIntentionRequest $request): bool
    {
        if (! MassIntentionStatus::isOpen($request->status)) {
            return false;
        }
        if ($request->requested_date === null) {
            return false;
        }

        $today = DonationBusinessDate::today($tenantId);

        return $request->requested_date->toDateString() < $today;
    }

    public function applyAutomaticCloseIfDue(int $tenantId, MassIntentionRequest $request): MassIntentionRequest
    {
        if (! $this->shouldAutoCloseAfterSave($tenantId, $request)) {
            return $request;
        }

        $request->status = MassIntentionStatus::CLOSED;
        $request->closed_at = now();
        $request->closed_by_user_id = null;
        $request->close_source = MassIntentionCloseSource::AUTOMATIC;
        $request->save();

        $this->audits->record($tenantId, 'request.closed', null, $request->id, null, [
            'close_source' => MassIntentionCloseSource::AUTOMATIC,
        ]);

        return $request->fresh();
    }
}
