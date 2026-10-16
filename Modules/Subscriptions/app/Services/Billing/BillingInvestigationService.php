<?php

namespace Modules\Subscriptions\Services\Billing;

use Modules\Authentication\Models\User;
use Modules\Subscriptions\Models\BillingInvestigationItem;
use Modules\Subscriptions\Models\BillingOrder;
use Modules\Tenants\Models\Tenant;

final class BillingInvestigationService
{
    public function __construct(private readonly BillingAuditService $audit) {}

    public function openForOrder(BillingOrder $order, string $category, array $details = []): BillingInvestigationItem
    {
        $item = BillingInvestigationItem::query()->create([
            'category' => $category,
            'tenant_id' => $order->tenant_id,
            'status' => BillingInvestigationItem::STATUS_OPEN,
            'details' => array_merge($details, [
                'billing_order_id' => $order->id,
                'internal_reference' => $order->internal_reference,
            ]),
        ]);

        $this->audit->record('investigation_opened', null, null, (int) $order->tenant_id, null, null, [
            'investigation_id' => $item->id,
            'category' => $category,
        ]);

        return $item;
    }

    public function resolve(BillingInvestigationItem $item, User $resolver, string $note): BillingInvestigationItem
    {
        $item->forceFill([
            'status' => BillingInvestigationItem::STATUS_RESOLVED,
            'details' => array_merge((array) $item->details, [
                'resolution_note' => $note,
                'resolved_by' => $resolver->id,
                'resolved_at' => now()->toIso8601String(),
            ]),
        ])->save();

        $this->audit->record('investigation_resolved', $resolver->id, null, (int) $item->tenant_id, $note, null, [
            'investigation_id' => $item->id,
        ]);

        return $item->fresh();
    }
}
