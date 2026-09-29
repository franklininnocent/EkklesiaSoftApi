<?php

namespace Modules\MassIntentions\Services;

use Illuminate\Support\Facades\DB;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Support\MassObligationStatus;
class MassCelebrationWorkspaceService
{
    public function findForTenant(int $tenantId, string $id): MassCelebration
    {
        return MassCelebration::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    public function workspace(int $tenantId, string $celebrationId): array
    {
        $celebration = $this->findForTenant($tenantId, $celebrationId);

        $intentions = $this->intentionsForCelebration($tenantId, $celebrationId);

        return [
            'celebration' => [
                'id' => $celebration->id,
                'celebrated_on' => $celebration->celebrated_on?->format('Y-m-d'),
                'celebrated_at' => $celebration->celebrated_at,
                'place' => $celebration->place,
                'celebrant_name' => $celebration->celebrant_name,
                'status' => $celebration->status,
            ],
            'intentions' => $intentions,
            'can_mark_said' => $celebration->status === 'scheduled'
                && $celebration->celebrated_on !== null
                && $celebration->celebrated_on->toDateString() <= DonationBusinessDate::today($tenantId),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function intentionsForCelebration(int $tenantId, string $celebrationId): array
    {
        return DB::table('mass_intention_assignments as a')
            ->join('mass_intention_obligations as o', 'o.id', '=', 'a.obligation_id')
            ->join('mass_intention_requests as r', 'r.id', '=', 'o.request_id')
            ->leftJoin('mass_intention_fulfilments as f', function ($join) use ($celebrationId): void {
                $join->on('f.obligation_id', '=', 'o.id')
                    ->where('f.celebration_id', $celebrationId)
                    ->whereNull('f.undone_at');
            })
            ->where('a.tenant_id', $tenantId)
            ->where('a.celebration_id', $celebrationId)
            ->whereNull('a.unassigned_at')
            ->select([
                'o.id as obligation_id',
                'o.sequence',
                'o.status as obligation_status',
                'r.id as request_id',
                'r.beneficiary_name',
                'r.intention_text',
                'r.mass_count_accepted',
                'f.id as fulfilment_id',
                'f.fulfilled_at',
            ])
            ->orderBy('r.beneficiary_name')
            ->get()
            ->map(function ($row) {
                return [
                    'obligation_id' => $row->obligation_id,
                    'request_id' => $row->request_id,
                    'beneficiary_name' => $row->beneficiary_name,
                    'intention_text' => $row->intention_text,
                    'sequence' => (int) $row->sequence,
                    'mass_total' => (int) ($row->mass_count_accepted ?? 1),
                    'is_said' => $row->fulfilment_id !== null || $row->obligation_status === MassObligationStatus::SAID,
                    'fulfilment_id' => $row->fulfilment_id,
                    'fulfilled_at' => $row->fulfilled_at,
                ];
            })
            ->values()
            ->all();
    }
}
