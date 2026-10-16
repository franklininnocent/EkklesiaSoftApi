<?php

namespace Modules\Subscriptions\Services\Billing;

use Illuminate\Support\Facades\Log;
use Modules\Notifications\Contracts\NotificationPublisherContract;
use Modules\Notifications\Support\InboxScope;
use Modules\Notifications\Support\NotificationIntent;
use Modules\Subscriptions\Models\BillingOfflineClaim;
use Modules\Subscriptions\Models\BillingOrder;
use Modules\Tenants\Models\Tenant;
use Throwable;

/**
 * In-app billing notices. Failures never block payment or claim processing.
 */
final class BillingNotificationPublisher
{
    public const OFFLINE_SUBMITTED = 'subscriptions.billing.offline_claim.submitted';

    public const OFFLINE_DECIDED = 'subscriptions.billing.offline_claim.decided';

    public const PAYMENT_FULFILLED = 'subscriptions.billing.payment.fulfilled';

    public function offlineClaimSubmitted(BillingOfflineClaim $claim): void
    {
        $tenant = Tenant::query()->find($claim->tenant_id);
        $this->publish(new NotificationIntent(
            definitionCode: self::OFFLINE_SUBMITTED,
            actor: null,
            subjectType: 'billing_offline_claim',
            subjectId: (string) $claim->id,
            tenantId: null,
            scope: InboxScope::Platform,
            occurrenceId: 'billing-offline-claim-'.$claim->id.'-submitted',
            data: [
                'church_name' => (string) ($tenant?->name ?? 'A church'),
                'deep_link_route' => '/settings/billing/offline-claims',
            ],
            collapseKey: 'billing.offline_claim.'.$claim->id,
        ), ['claim_id' => $claim->id]);
    }

    public function offlineClaimDecided(BillingOfflineClaim $claim, string $statusLabel): void
    {
        $this->publish(new NotificationIntent(
            definitionCode: self::OFFLINE_DECIDED,
            actor: null,
            subjectType: 'billing_offline_claim',
            subjectId: (string) $claim->id,
            tenantId: (int) $claim->tenant_id,
            scope: InboxScope::Tenant,
            occurrenceId: 'billing-offline-claim-'.$claim->id.'-'.strtolower($claim->status),
            data: [
                'status_label' => $statusLabel,
                'deep_link_route' => '/settings/my-subscription',
            ],
            collapseKey: 'billing.offline_claim.'.$claim->id,
        ), ['claim_id' => $claim->id]);
    }

    public function paymentFulfilled(Tenant $tenant, BillingOrder $order): void
    {
        $this->publish(new NotificationIntent(
            definitionCode: self::PAYMENT_FULFILLED,
            actor: null,
            subjectType: 'billing_order',
            subjectId: (string) $order->id,
            tenantId: (int) $tenant->id,
            scope: InboxScope::Tenant,
            occurrenceId: 'billing-order-'.$order->id.'-fulfilled',
            data: [
                'reference' => (string) $order->internal_reference,
                'deep_link_route' => '/settings/my-subscription',
            ],
            collapseKey: 'billing.order.'.$order->id,
        ), ['order_id' => $order->id]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function publish(NotificationIntent $intent, array $context): void
    {
        if (! app()->bound(NotificationPublisherContract::class)) {
            return;
        }

        try {
            app(NotificationPublisherContract::class)->publish($intent);
        } catch (Throwable $e) {
            Log::warning('Billing notification failed', $context + [
                'definition' => $intent->definitionCode,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
