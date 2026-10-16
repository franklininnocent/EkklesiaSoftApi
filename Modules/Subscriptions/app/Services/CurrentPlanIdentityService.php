<?php

namespace Modules\Subscriptions\Services;

use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionService;

/**
 * Authoritative current-plan identity for a church.
 *
 * Matching uses catalog plan id and code (and the subscribed version when present).
 * Display names are never used to decide which comparison card is current.
 */
class CurrentPlanIdentityService
{
    public const SOURCE_SUBSCRIPTION = 'tenant_subscription';

    public const SOURCE_PLAN_KEY = 'tenant_plan_key';

    public const SOURCE_UNMATCHED = 'unmatched';

    public function __construct(private readonly SubscriptionService $lifecycle) {}

    /**
     * Resolve a catalog plan from a stored tenant plan key or public code.
     */
    public function findPlan(?string $keyOrCode): ?Plan
    {
        $raw = trim((string) $keyOrCode);
        if ($raw === '') {
            return null;
        }

        $code = strtoupper($raw);
        $key = strtolower($raw);

        return Plan::withTrashed()
            ->whereNotNull('code')
            ->where(function ($query) use ($raw, $code, $key) {
                $query->where('key', $raw)
                    ->orWhere('key', $key)
                    ->orWhere('code', $code);
            })
            ->orderByRaw('CASE WHEN code = ? THEN 0 WHEN key = ? THEN 1 WHEN key = ? THEN 2 ELSE 3 END', [$code, $raw, $key])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    public function matches(Plan $plan, array $identity): bool
    {
        if (! ($identity['matched'] ?? false)) {
            return false;
        }

        if (($identity['id'] ?? null) !== null && (int) $identity['id'] === (int) $plan->id) {
            return true;
        }

        $code = strtoupper((string) ($identity['code'] ?? ''));

        return $code !== '' && $code === strtoupper((string) $plan->code);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(Tenant $tenant): array
    {
        $status = $this->lifecycle->resolveStatus($tenant);
        $endsAt = $tenant->subscription_ends_at?->toIso8601String();
        $isLifetime = $status === SubscriptionService::STATUS_LIFETIME;

        $subscription = TenantSubscription::query()
            ->forTenant((int) $tenant->id)
            ->current()
            ->with(['plan', 'version'])
            ->first();

        if ($subscription && $subscription->plan) {
            return $this->present(
                $subscription->plan,
                $subscription->version,
                self::SOURCE_SUBSCRIPTION,
                $status,
                $endsAt,
                $isLifetime,
            );
        }

        $plan = $this->findPlan($tenant->plan);
        if ($plan) {
            $version = $plan->activeVersion()->first();

            return $this->present(
                $plan,
                $version,
                self::SOURCE_PLAN_KEY,
                $status,
                $endsAt,
                $isLifetime,
            );
        }

        return [
            'matched' => false,
            'source' => self::SOURCE_UNMATCHED,
            'id' => null,
            'code' => null,
            'key' => $tenant->plan,
            'name' => $tenant->plan,
            'pricing_type' => null,
            'is_legacy' => false,
            'status' => null,
            'is_public' => false,
            'is_archived' => false,
            'listed_in_catalog' => false,
            'version_id' => null,
            'version_number' => null,
            'subscription_status' => $status,
            'subscription_ends_at' => $endsAt,
            'is_lifetime' => $isLifetime,
        ];
    }

    /**
     * Compact plan object shared by entitlements, overview and my-subscription.
     *
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>|null
     */
    public function planSummary(array $identity): ?array
    {
        if (! ($identity['matched'] ?? false) && empty($identity['key']) && empty($identity['code'])) {
            return null;
        }

        return [
            'id' => $identity['id'] ?? null,
            'code' => $identity['code'] ?? null,
            'key' => $identity['key'] ?? null,
            'name' => $identity['name'] ?? ($identity['key'] ?? null),
            'pricing_type' => $identity['pricing_type'] ?? null,
            'is_legacy' => (bool) ($identity['is_legacy'] ?? false),
            'version_number' => $identity['version_number'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(
        Plan $plan,
        ?PlanVersion $version,
        string $source,
        string $status,
        ?string $endsAt,
        bool $isLifetime,
    ): array {
        $listed = Plan::query()->publiclyListed()->whereKey($plan->id)->exists();

        return [
            'matched' => true,
            'source' => $source,
            'id' => (int) $plan->id,
            'code' => $plan->code,
            'key' => $plan->key,
            'name' => $plan->name,
            'pricing_type' => $plan->pricing_type,
            'is_legacy' => (bool) $plan->is_legacy,
            'status' => $plan->status,
            'is_public' => (bool) $plan->is_public,
            'is_archived' => $plan->isArchived() || $plan->status === Plan::STATUS_ARCHIVED,
            'listed_in_catalog' => $listed,
            'version_id' => $version ? (int) $version->id : null,
            'version_number' => $version ? (int) $version->version_number : null,
            'subscription_status' => $status,
            'subscription_ends_at' => $endsAt,
            'is_lifetime' => $isLifetime,
        ];
    }
}
