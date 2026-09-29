<?php

namespace Modules\Subscriptions\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\SubscriptionUpgradeRequest;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Tenants\Models\Tenant;

/**
 * Church → Ekklesia plan change requests. No payment is taken; an Ekklesia administrator
 * reviews the request and, on approval, the plan changes through SubscriptionPlanChangeService.
 */
class UpgradeRequestService
{
    public function __construct(
        private readonly SubscriptionPlanChangeService $changes,
        private readonly SubscriptionAuditService $audit,
        private readonly UpgradeRequestNotifier $notifier,
    ) {}

    /**
     * Creates a request, or updates the church's request that is waiting for more information.
     *
     * @param  array{plan_id?: int|null, plan_code?: string|null, billing_interval?: string|null, feature_code?: string|null, message?: string|null}  $data
     */
    public function submit(Tenant $tenant, User $actor, array $data): SubscriptionUpgradeRequest
    {
        $plans = Plan::query()->publiclyListed();
        $plan = ! empty($data['plan_id'])
            ? $plans->find((int) $data['plan_id'])
            : $plans->where('code', strtoupper((string) ($data['plan_code'] ?? '')))->first();
        if (! $plan) {
            throw SubscriptionException::planNotAvailable();
        }
        $version = $this->changes->assertAssignable($plan);

        $interval = isset($data['billing_interval']) ? strtoupper((string) $data['billing_interval']) : null;
        if ($interval !== null && ! in_array($interval, (array) $version->billing_intervals, true)) {
            throw SubscriptionException::changeNotAllowed('This billing option is not offered for the selected plan.');
        }

        $currentPlanId = TenantSubscription::query()->forTenant((int) $tenant->id)->current()->value('plan_id');
        if ($currentPlanId !== null && (int) $currentPlanId === (int) $plan->id) {
            throw SubscriptionException::changeNotAllowed('Your church is already on this plan.');
        }

        $featureCode = $this->knownFeatureCode($data['feature_code'] ?? null);
        $message = $this->cleanText($data['message'] ?? null);

        try {
            $request = DB::transaction(function () use ($tenant, $actor, $plan, $interval, $featureCode, $message, $currentPlanId) {
                $open = SubscriptionUpgradeRequest::query()
                    ->where('tenant_id', $tenant->id)
                    ->whereIn('status', SubscriptionUpgradeRequest::OPEN_STATUSES)
                    ->lockForUpdate()
                    ->first();

                if ($open && $open->status === SubscriptionUpgradeRequest::STATUS_PENDING) {
                    throw SubscriptionException::changeNotAllowed('Your church already has a plan request waiting for review.');
                }

                $attributes = [
                    'requested_by' => $actor->id,
                    'current_plan_id' => $currentPlanId,
                    'requested_plan_id' => $plan->id,
                    'requested_billing_interval' => $interval,
                    'feature_code' => $featureCode,
                    'message' => $message,
                    'status' => SubscriptionUpgradeRequest::STATUS_PENDING,
                ];

                if ($open) {
                    $open->forceFill($attributes)->save();

                    return $open;
                }

                return SubscriptionUpgradeRequest::query()->create($attributes + ['tenant_id' => $tenant->id]);
            });
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                throw SubscriptionException::changeNotAllowed('Your church already has a plan request waiting for review.');
            }
            throw $e;
        }

        $this->audit->tenant((int) $tenant->id, 'upgrade_request_submitted', [], [
            'request_id' => $request->id,
            'requested_plan' => $plan->code,
            'billing_interval' => $interval,
            'feature_code' => $featureCode,
        ], TenantSubscription::SOURCE_UPGRADE_REQUEST);

        $this->notifier->submitted($request->fresh(['tenant', 'requestedPlan']));

        return $request->fresh(['requestedPlan', 'currentPlan', 'requester']);
    }

    /**
     * Approves by changing the plan through the single plan-change path (impact confirmation applies).
     *
     * @param  array{billing_interval?: string|null, contracted_price?: string|null, scheduled_for?: string|null, confirm_impact?: bool, note?: string|null}  $options
     */
    public function approve(SubscriptionUpgradeRequest $request, User $actor, array $options): SubscriptionUpgradeRequest
    {
        $this->assertOpen($request);
        $tenant = Tenant::query()->findOrFail($request->tenant_id);
        $plan = Plan::query()->catalog()->findOrFail($request->requested_plan_id);
        $note = $this->cleanText($options['note'] ?? null);

        $subscription = $this->changes->assign($tenant, $plan, [
            'billing_interval' => $options['billing_interval'] ?? $request->requested_billing_interval,
            'contracted_price' => $options['contracted_price'] ?? null,
            'scheduled_for' => $options['scheduled_for'] ?? null,
            'confirm_impact' => (bool) ($options['confirm_impact'] ?? false),
            'reason' => Str::limit("Upgrade request #{$request->id}".($note ? ": {$note}" : ''), 500, ''),
            'source' => TenantSubscription::SOURCE_UPGRADE_REQUEST,
        ], $actor);

        return $this->decide($request, $actor, SubscriptionUpgradeRequest::STATUS_APPROVED, $note, (int) $subscription->id);
    }

    public function reject(SubscriptionUpgradeRequest $request, User $actor, string $note): SubscriptionUpgradeRequest
    {
        $this->assertOpen($request);

        return $this->decide($request, $actor, SubscriptionUpgradeRequest::STATUS_REJECTED, $this->cleanText($note));
    }

    public function requestInfo(SubscriptionUpgradeRequest $request, User $actor, string $note): SubscriptionUpgradeRequest
    {
        if ($request->status !== SubscriptionUpgradeRequest::STATUS_PENDING) {
            throw SubscriptionException::invalidState('Only requests waiting for review can be sent back for more information.');
        }

        return $this->decide($request, $actor, SubscriptionUpgradeRequest::STATUS_INFO_REQUESTED, $this->cleanText($note));
    }

    /**
     * @return array<string, mixed>
     */
    public function present(SubscriptionUpgradeRequest $request, bool $forPlatform = false): array
    {
        $request->loadMissing(['requestedPlan', 'currentPlan', 'requester', 'reviewer']);
        $data = [
            'id' => $request->id,
            'status' => $request->status,
            'requested_plan' => $request->requestedPlan ? ['id' => $request->requestedPlan->id, 'code' => $request->requestedPlan->code, 'name' => $request->requestedPlan->name] : null,
            'current_plan' => $request->currentPlan ? ['id' => $request->currentPlan->id, 'code' => $request->currentPlan->code, 'name' => $request->currentPlan->name] : null,
            'billing_interval' => $request->requested_billing_interval,
            'feature_code' => $request->feature_code,
            'message' => $request->message,
            'review_notes' => $request->review_notes,
            'requested_by_name' => $request->requester?->name,
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            'created_at' => $request->created_at?->toIso8601String(),
            'updated_at' => $request->updated_at?->toIso8601String(),
        ];

        if ($forPlatform) {
            $request->loadMissing('tenant');
            $data['tenant'] = $request->tenant ? ['id' => $request->tenant->id, 'name' => $request->tenant->name] : null;
            $data['reviewed_by_name'] = $request->reviewer?->name;
            $data['resulting_subscription_id'] = $request->resulting_subscription_id;
        }

        return $data;
    }

    private function decide(SubscriptionUpgradeRequest $request, User $actor, string $status, ?string $note, ?int $subscriptionId = null): SubscriptionUpgradeRequest
    {
        $before = ['status' => $request->status];
        $request->forceFill([
            'status' => $status,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'review_notes' => $note,
            'resulting_subscription_id' => $subscriptionId ?? $request->resulting_subscription_id,
        ])->save();

        $this->audit->tenant((int) $request->tenant_id, 'upgrade_request_'.strtolower($status), $before, [
            'request_id' => $request->id,
            'status' => $status,
            'resulting_subscription_id' => $subscriptionId,
        ], TenantSubscription::SOURCE_UPGRADE_REQUEST, $note);

        $this->notifier->decided($request->fresh(['tenant', 'requestedPlan']));

        return $request->fresh(['requestedPlan', 'currentPlan', 'requester', 'reviewer']);
    }

    private function assertOpen(SubscriptionUpgradeRequest $request): void
    {
        if (! $request->isOpen()) {
            throw SubscriptionException::invalidState('This request has already been reviewed.');
        }
    }

    private function knownFeatureCode(mixed $code): ?string
    {
        if (! is_string($code) || $code === '') {
            return null;
        }
        $code = strtoupper($code);

        return Feature::query()->where('code', $code)->exists() ? $code : null;
    }

    private function cleanText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $clean = trim(strip_tags($value));

        return $clean === '' ? null : Str::limit($clean, 1000, '');
    }
}
