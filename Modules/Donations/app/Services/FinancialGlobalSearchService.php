<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\DonationReceipt;
use Modules\Family\Models\Family;

class FinancialGlobalSearchService
{
    /**
     * @return array<string, mixed>
     */
    public function search(int $tenantId, string $query, int $limit = 6): array
    {
        $term = trim($query);
        if (strlen($term) < 2) {
            return [
                'query' => $term,
                'groups' => [],
                'total' => 0,
            ];
        }

        $like = '%' . $term . '%';
        $phoneDigits = preg_replace('/\D+/', '', $term) ?? '';

        $families = Family::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where(function ($builder) use ($like, $phoneDigits): void {
                $builder->where('family_name', 'like', $like)
                    ->orWhere('family_code', 'like', $like)
                    ->orWhere('head_of_family', 'like', $like);

                if ($phoneDigits !== '') {
                    $builder->orWhereHas('members', function ($memberQuery) use ($phoneDigits): void {
                        $memberQuery->where('phone', 'like', '%' . $phoneDigits . '%');
                    });
                }
            })
            ->orderBy('family_name')
            ->limit($limit)
            ->get(['id', 'family_name', 'family_code', 'head_of_family'])
            ->map(fn (Family $family) => [
                'type' => 'family',
                'id' => $family->id,
                'title' => $family->family_name,
                'subtitle' => trim(($family->family_code ?: '') . ($family->head_of_family ? ' · ' . $family->head_of_family : '')),
                'route' => '/families/' . $family->id,
            ])
            ->values()
            ->all();

        $projects = DonationProject::query()
            ->where('tenant_id', $tenantId)
            ->where('entity_kind', 'project')
            ->where('status', 'active')
            ->where(function ($builder) use ($like): void {
                $builder->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like);
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'code', 'target_amount', 'raised_amount'])
            ->map(fn (DonationProject $project) => [
                'type' => 'project',
                'id' => $project->id,
                'title' => $project->name,
                'subtitle' => $project->code,
                'route' => '/donations/projects',
            ])
            ->values()
            ->all();

        $campaigns = DonationProject::query()
            ->where('tenant_id', $tenantId)
            ->where('entity_kind', 'campaign')
            ->where('status', 'active')
            ->where(function ($builder) use ($like): void {
                $builder->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like);
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'code', 'campaign_type', 'target_amount', 'raised_amount'])
            ->map(fn (DonationProject $campaign) => [
                'type' => 'campaign',
                'id' => $campaign->id,
                'title' => $campaign->name,
                'subtitle' => trim(($campaign->code ?: '') . ($campaign->campaign_type ? ' · ' . $campaign->campaign_type : '')),
                'route' => '/donations/campaigns',
            ])
            ->values()
            ->all();

        $receipts = DonationReceipt::forTenant($tenantId)
            ->with(['payment:id,payment_number,payer_name,amount,family_id', 'payment.family:id,family_name'])
            ->where(function ($builder) use ($like): void {
                $builder->where('receipt_number', 'like', $like)
                    ->orWhereHas('payment', function ($paymentQuery) use ($like): void {
                        $paymentQuery->where('payment_number', 'like', $like)
                            ->orWhere('payer_name', 'like', $like);
                    });
            })
            ->orderByDesc('issued_on')
            ->limit($limit)
            ->get()
            ->map(fn (DonationReceipt $receipt) => [
                'type' => 'receipt',
                'id' => $receipt->id,
                'title' => $receipt->receipt_number,
                'subtitle' => trim(
                    ($receipt->payment?->payer_name ?: 'Receipt')
                    . ' · '
                    . number_format((float) ($receipt->payment?->amount ?? 0), 2)
                ),
                'route' => '/donations/receipts',
            ])
            ->values()
            ->all();

        $payments = DonationPayment::forTenant($tenantId)
            ->with('family:id,family_name,family_code')
            ->where(function ($builder) use ($like): void {
                $builder->where('payment_number', 'like', $like)
                    ->orWhere('payer_name', 'like', $like);
            })
            ->orderByDesc('payment_date')
            ->limit($limit)
            ->get(['id', 'payment_number', 'payer_name', 'amount', 'family_id'])
            ->map(fn (DonationPayment $payment) => [
                'type' => 'payment',
                'id' => $payment->id,
                'title' => $payment->payment_number,
                'subtitle' => trim(($payment->payer_name ?: 'Payment') . ' · ' . number_format((float) $payment->amount, 2)),
                'route' => '/donations/payments',
            ])
            ->values()
            ->all();

        $groups = array_values(array_filter([
            ['type' => 'families', 'label' => 'Families', 'items' => $families],
            ['type' => 'projects', 'label' => 'Projects', 'items' => $projects],
            ['type' => 'campaigns', 'label' => 'Campaigns', 'items' => $campaigns],
            ['type' => 'receipts', 'label' => 'Receipts', 'items' => $receipts],
            ['type' => 'payments', 'label' => 'Payments', 'items' => $payments],
        ], fn (array $group): bool => count($group['items']) > 0));

        return [
            'query' => $term,
            'groups' => $groups,
            'total' => collect($groups)->sum(fn (array $group) => count($group['items'])),
        ];
    }
}
