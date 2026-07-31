<?php

namespace Modules\Donations\Services;

use Illuminate\Support\Facades\DB;
use Modules\Donations\Models\DonationPayment;
use Modules\Family\Models\Family;

class GeographicBreakdownService
{
    /**
     * @return array<string, mixed>
     */
    public function build(int $tenantId): array
    {
        return [
            'by_bcc' => $this->aggregateByBcc($tenantId),
            'by_city' => $this->aggregateByCity($tenantId),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function aggregateByBcc(int $tenantId): array
    {
        $rows = DonationPayment::query()
            ->where('donation_payments.tenant_id', $tenantId)
            ->where('donation_payments.status', 'succeeded')
            ->whereNotNull('donation_payments.family_id')
            ->join('families', 'families.id', '=', 'donation_payments.family_id')
            ->leftJoin('bccs', 'bccs.id', '=', 'families.bcc_id')
            ->where('families.tenant_id', $tenantId)
            ->selectRaw("COALESCE(bccs.name, 'Unassigned Area') as area_name")
            ->selectRaw('families.bcc_id as area_id')
            ->selectRaw('COUNT(DISTINCT families.id) as family_count')
            ->selectRaw('COALESCE(SUM(donation_payments.amount), 0) as collected')
            ->groupBy('families.bcc_id', 'bccs.name')
            ->orderByDesc('collected')
            ->limit(10)
            ->get();

        return $rows->map(function ($row) {
            $families = max(1, (int) $row->family_count);

            return [
                'area_id' => $row->area_id,
                'area_name' => $row->area_name,
                'collected' => round((float) $row->collected, 2),
                'family_count' => (int) $row->family_count,
                'participation_density' => round((float) $row->collected / $families, 2),
            ];
        })->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function aggregateByCity(int $tenantId): array
    {
        $rows = DonationPayment::query()
            ->where('donation_payments.tenant_id', $tenantId)
            ->where('donation_payments.status', 'succeeded')
            ->whereNotNull('donation_payments.family_id')
            ->join('families', 'families.id', '=', 'donation_payments.family_id')
            ->where('families.tenant_id', $tenantId)
            ->selectRaw("COALESCE(NULLIF(TRIM(families.city), ''), 'Unknown') as area_name")
            ->selectRaw('COUNT(DISTINCT families.id) as family_count')
            ->selectRaw('COALESCE(SUM(donation_payments.amount), 0) as collected')
            ->groupBy('area_name')
            ->orderByDesc('collected')
            ->limit(10)
            ->get();

        return $rows->map(function ($row) {
            $families = max(1, (int) $row->family_count);

            return [
                'area_name' => $row->area_name,
                'collected' => round((float) $row->collected, 2),
                'family_count' => (int) $row->family_count,
                'participation_density' => round((float) $row->collected / $families, 2),
            ];
        })->values()->all();
    }
}
