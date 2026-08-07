<?php

namespace Modules\MinistriesAssociations\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Family\Events\FamilyMemberStatusChanged;
use Modules\MinistriesAssociations\Services\MinistriesCensusCascadeService;

class HandleFamilyMemberStatusChanged
{
    public function __construct(private readonly MinistriesCensusCascadeService $censusCascadeService)
    {
    }

    public function handle(FamilyMemberStatusChanged $event): void
    {
        try {
            $result = $this->censusCascadeService->cascade(
                $event->tenantId,
                $event->familyMemberId,
                $event->previousStatus,
                $event->newStatus,
                $event->effectiveDate,
            );

            if ($result['memberships_exited'] > 0 || $result['leadership_vacated'] > 0) {
                Log::info('Ministries census cascade completed', [
                    'tenant_id' => $event->tenantId,
                    'family_member_id' => $event->familyMemberId,
                    'previous_status' => $event->previousStatus,
                    'new_status' => $event->newStatus,
                    'memberships_exited' => $result['memberships_exited'],
                    'leadership_vacated' => $result['leadership_vacated'],
                ]);
            }
        } catch (\Throwable $exception) {
            Log::error('Ministries census cascade failed', [
                'tenant_id' => $event->tenantId,
                'family_member_id' => $event->familyMemberId,
                'previous_status' => $event->previousStatus,
                'new_status' => $event->newStatus,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
