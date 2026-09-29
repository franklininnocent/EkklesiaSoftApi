<?php

namespace Modules\Subscriptions\Services;

use Illuminate\Support\Facades\Log;
use Modules\Notifications\Contracts\NotificationPublisherContract;
use Modules\Notifications\Support\InboxScope;
use Modules\Notifications\Support\NotificationIntent;
use Modules\Subscriptions\Models\SubscriptionUpgradeRequest;
use Throwable;

/**
 * In-app notices for plan requests: reviewers when a church asks, church admins when Ekklesia decides.
 * Notification failures never block the request itself.
 */
class UpgradeRequestNotifier
{
    public const SUBMITTED = 'subscriptions.upgrade_request.submitted';

    public const DECIDED = 'subscriptions.upgrade_request.decided';

    private const STATUS_LABELS = [
        SubscriptionUpgradeRequest::STATUS_APPROVED => 'Approved',
        SubscriptionUpgradeRequest::STATUS_REJECTED => 'Not approved',
        SubscriptionUpgradeRequest::STATUS_INFO_REQUESTED => 'More information needed',
    ];

    public function submitted(SubscriptionUpgradeRequest $request): void
    {
        $this->publish(new NotificationIntent(
            definitionCode: self::SUBMITTED,
            actor: null,
            subjectType: 'subscription_upgrade_request',
            subjectId: (string) $request->id,
            tenantId: null,
            scope: InboxScope::Platform,
            occurrenceId: 'upgrade-request-'.$request->id.'-'.$request->updated_at?->timestamp,
            data: [
                'church_name' => (string) ($request->tenant?->name ?? 'A church'),
                'plan_name' => (string) ($request->requestedPlan?->name ?? 'a new plan'),
                'deep_link_route' => '/settings/subscription/requests',
            ],
            collapseKey: 'subscriptions.upgrade_request.'.$request->id,
        ), $request);
    }

    public function decided(SubscriptionUpgradeRequest $request): void
    {
        $this->publish(new NotificationIntent(
            definitionCode: self::DECIDED,
            actor: null,
            subjectType: 'subscription_upgrade_request',
            subjectId: (string) $request->id,
            tenantId: (int) $request->tenant_id,
            scope: InboxScope::Tenant,
            occurrenceId: 'upgrade-request-'.$request->id.'-'.strtolower($request->status),
            data: [
                'plan_name' => (string) ($request->requestedPlan?->name ?? 'the requested plan'),
                'status_label' => self::STATUS_LABELS[$request->status] ?? 'Updated',
                'deep_link_route' => '/settings/my-subscription',
            ],
            collapseKey: 'subscriptions.upgrade_request.'.$request->id,
        ), $request);
    }

    private function publish(NotificationIntent $intent, SubscriptionUpgradeRequest $request): void
    {
        if (! app()->bound(NotificationPublisherContract::class)) {
            return;
        }

        try {
            app(NotificationPublisherContract::class)->publish($intent);
        } catch (Throwable $e) {
            Log::warning('Upgrade request notification failed', [
                'request_id' => $request->id,
                'definition' => $intent->definitionCode,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
