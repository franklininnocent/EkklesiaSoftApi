<?php

namespace Modules\MassIntentions\Services;

use Illuminate\Support\Facades\DB;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\MassIntentions\Support\MassObligationStatus;

class MassIntentionRegisterService
{
    /**
     * Canonical register: accepted intentions with progress.
     *
     * @return list<array<string, mixed>>
     */
    public function canonicalRegister(int $tenantId, int $limit = 500): array
    {
        $rows = DB::table('mass_intention_requests as r')
            ->leftJoin('mass_intention_obligations as o', 'o.request_id', '=', 'r.id')
            ->where('r.tenant_id', $tenantId)
            ->where('r.status', MassIntentionStatus::ACCEPTED)
            ->select([
                'r.id as request_id',
                'r.beneficiary_name',
                'r.intention_text',
                'r.requested_date',
                'r.accepted_at',
                'r.mass_count_accepted',
                DB::raw("sum(case when o.status = 'said' then 1 else 0 end) as said_count"),
            ])
            ->groupBy(
                'r.id',
                'r.beneficiary_name',
                'r.intention_text',
                'r.requested_date',
                'r.accepted_at',
                'r.mass_count_accepted'
            )
            ->orderByDesc('r.accepted_at')
            ->limit($limit)
            ->get();

        return $rows->map(function ($row) {
            $total = (int) ($row->mass_count_accepted ?? 0);
            $said = (int) ($row->said_count ?? 0);

            return [
                'request_id' => $row->request_id,
                'beneficiary_name' => $row->beneficiary_name,
                'intention_text' => $row->intention_text,
                'requested_date' => $row->requested_date,
                'accepted_at' => $row->accepted_at,
                'said' => $said,
                'total' => $total,
                'progress' => $total > 0 ? "{$said} of {$total}" : '—',
            ];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function massListReport(int $tenantId, string $from, string $to): array
    {
        $rows = DB::table('mass_celebrations as c')
            ->leftJoin('mass_intention_assignments as a', function ($join): void {
                $join->on('a.celebration_id', '=', 'c.id')->whereNull('a.unassigned_at');
            })
            ->where('c.tenant_id', $tenantId)
            ->whereBetween('c.celebrated_on', [$from, $to])
            ->where(function ($q): void {
                $q->where('c.generation_status', 'active')
                    ->orWhere('c.status', 'cancelled');
            })
            ->select([
                'c.id',
                'c.celebrated_on',
                'c.celebrated_at',
                'c.place',
                'c.celebrant_name',
                'c.status',
                DB::raw('count(distinct a.obligation_id) as intention_count'),
            ])
            ->groupBy('c.id', 'c.celebrated_on', 'c.celebrated_at', 'c.place', 'c.celebrant_name', 'c.status')
            ->orderBy('c.celebrated_on')
            ->orderBy('c.celebrated_at')
            ->get();

        return $rows->map(fn ($row) => [
            'celebration_id' => $row->id,
            'celebrated_on' => $row->celebrated_on,
            'celebrated_at' => $row->celebrated_at,
            'place' => $row->place,
            'celebrant_name' => $row->celebrant_name,
            'status' => $row->status,
            'intention_count' => (int) $row->intention_count,
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function stillToSayReport(int $tenantId, int $limit = 500): array
    {
        $rows = DB::table('mass_intention_obligations as o')
            ->join('mass_intention_requests as r', 'r.id', '=', 'o.request_id')
            ->where('o.tenant_id', $tenantId)
            ->where('r.status', MassIntentionStatus::ACCEPTED)
            ->whereIn('o.status', [MassObligationStatus::PENDING, MassObligationStatus::SCHEDULED])
            ->select([
                'o.id as obligation_id',
                'r.beneficiary_name',
                'r.intention_text',
                'o.sequence',
                'o.status',
            ])
            ->orderBy('r.beneficiary_name')
            ->orderBy('o.sequence')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'obligation_id' => $row->obligation_id,
            'beneficiary_name' => $row->beneficiary_name,
            'intention_text' => $row->intention_text,
            'sequence' => (int) $row->sequence,
            'status' => $row->status === MassObligationStatus::SCHEDULED ? 'Scheduled' : 'Not scheduled',
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function offeringsReport(int $tenantId, int $limit = 500): array
    {
        $rows = DB::table('mass_intention_offering_receipts as rec')
            ->join('mass_intention_offerings as off', 'off.id', '=', 'rec.offering_id')
            ->join('mass_intention_requests as r', 'r.id', '=', 'off.request_id')
            ->where('rec.tenant_id', $tenantId)
            ->whereNull('rec.voided_at')
            ->select([
                'rec.receipt_number',
                'rec.amount',
                'rec.payment_method',
                'rec.received_on',
                'r.beneficiary_name',
                'r.id as request_id',
            ])
            ->orderByDesc('rec.received_on')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'receipt_number' => $row->receipt_number,
            'amount' => $row->amount,
            'payment_method' => $row->payment_method,
            'received_on' => $row->received_on,
            'beneficiary_name' => $row->beneficiary_name,
            'request_id' => $row->request_id,
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function massesSaidReport(int $tenantId, int $limit = 500): array
    {
        $rows = DB::table('mass_intention_fulfilments as f')
            ->join('mass_intention_obligations as o', 'o.id', '=', 'f.obligation_id')
            ->join('mass_intention_requests as r', 'r.id', '=', 'o.request_id')
            ->leftJoin('mass_celebrations as c', 'c.id', '=', 'f.celebration_id')
            ->where('f.tenant_id', $tenantId)
            ->whereNull('f.undone_at')
            ->select([
                'f.fulfilled_at',
                'r.beneficiary_name',
                'r.intention_text',
                'o.sequence',
                'c.celebrated_on',
                'f.celebrant_override',
                'c.celebrant_name',
            ])
            ->orderByDesc('f.fulfilled_at')
            ->limit($limit)
            ->get();

        return $rows->map(function ($row) {
            $priest = $row->celebrant_override ?: $row->celebrant_name;

            return [
                'said_on' => $row->fulfilled_at,
                'mass_day' => $row->celebrated_on,
                'beneficiary_name' => $row->beneficiary_name,
                'intention_text' => $row->intention_text,
                'sequence' => (int) $row->sequence,
                'celebrant' => $priest,
            ];
        })->all();
    }
}
