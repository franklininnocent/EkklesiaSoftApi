<?php

namespace Modules\MassIntentions\Services;

use Illuminate\Support\Facades\DB;
use Modules\MassIntentions\Models\MassIntentionAudit;
use Modules\MassIntentions\Support\MassObligationStatus;

final class MassIntentionRequestHistoryService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function assignmentsForRequest(int $tenantId, string $requestId): array
    {
        $rows = DB::table('mass_intention_assignments as a')
            ->join('mass_intention_obligations as o', 'o.id', '=', 'a.obligation_id')
            ->join('mass_celebrations as c', 'c.id', '=', 'a.celebration_id')
            ->where('a.tenant_id', $tenantId)
            ->where('o.request_id', $requestId)
            ->orderByDesc('a.assigned_at')
            ->select([
                'a.id as assignment_id',
                'a.obligation_id',
                'a.celebration_id',
                'a.assigned_at',
                'a.unassigned_at',
                'c.celebrated_on',
                'c.celebrated_at',
                'c.place',
                'c.status as mass_status',
                'c.generation_status',
                'c.suppression_reason',
                'o.status as obligation_status',
            ])
            ->get();

        return $rows->map(function ($row): array {
            $isActive = $row->unassigned_at === null;
            $isSaid = $row->obligation_status === MassObligationStatus::SAID;

            return [
                'assignment_id' => (string) $row->assignment_id,
                'obligation_id' => (string) $row->obligation_id,
                'celebration_id' => (string) $row->celebration_id,
                'assigned_at' => $row->assigned_at,
                'unassigned_at' => $row->unassigned_at,
                'is_active' => $isActive,
                'is_said' => $isSaid,
                'mass' => [
                    'celebrated_on' => $row->celebrated_on,
                    'celebrated_at' => $row->celebrated_at !== null ? substr((string) $row->celebrated_at, 0, 5) : null,
                    'place' => $row->place,
                    'status' => $row->mass_status,
                    'generation_status' => $row->generation_status,
                    'suppression_reason' => $row->suppression_reason,
                ],
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function auditsForRequest(int $tenantId, string $requestId): array
    {
        return MassIntentionAudit::query()
            ->where('tenant_id', $tenantId)
            ->where('request_id', $requestId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (MassIntentionAudit $row) => [
                'id' => $row->id,
                'event_type' => $row->event_type,
                'celebration_id' => $row->celebration_id,
                'actor_user_id' => $row->actor_user_id,
                'payload' => $row->payload,
                'created_at' => $row->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
