<?php

namespace Modules\Subscriptions\Services;

use App\Support\MoneyMath;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Models\TenantEntitlementOverride;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Subscriptions\Services\Catalog\LegacyColumns;
use Modules\Subscriptions\Services\Entitlements\EntitlementCatalog;
use Modules\Subscriptions\Services\Entitlements\EntitlementResolver;
use Modules\Subscriptions\Services\Entitlements\ResolvedEntitlements;
use Modules\Tenants\Contracts\TenantPlanAssigner;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionLifecycleNotificationPublisher;
use Modules\Tenants\Services\SubscriptionService;
use Modules\Tenants\Support\TenantCacheVersion;
use Throwable;

/**
 * Single write path for tenant plan assignment and plan changes.
 *
 * Pins the tenant to the plan's ACTIVE version, supersedes (never deletes) the previous
 * subscription row, keeps lifecycle dates on the tenant (existing SubscriptionService stays
 * the status engine), mirrors legacy columns for rollback, audits and notifies.
 */
class SubscriptionPlanChangeService implements TenantPlanAssigner
{
    public const MIRROR_MARKER = '_catalog_managed';

    public function __construct(
        private readonly EntitlementResolver $resolver,
        private readonly EntitlementCatalog $catalog,
        private readonly PlanImpactService $impact,
        private readonly SubscriptionPolicyService $policies,
        private readonly SubscriptionAuditService $audit,
        private readonly SubscriptionService $lifecycle,
    ) {}

    /**
     * @param  array{
     *   billing_interval?: string|null,
     *   contracted_price?: string|int|float|null,
     *   custom_limits?: array<string, int|null>|null,
     *   duration_months?: int|null,
     *   start_trial?: bool,
     *   scheduled_for?: CarbonInterface|string|null,
     *   reason?: string|null,
     *   confirm_impact?: bool,
     *   clear_suspension?: bool,
     *   source?: string,
     *   allow_unassignable?: bool,
     * }  $options
     */
    public function assign(Tenant $tenant, Plan $plan, array $options = [], ?User $actor = null): TenantSubscription
    {
        $allowUnassignable = (bool) ($options['allow_unassignable'] ?? false)
            || (bool) ($options['honor_accepted_schedule'] ?? false);
        $version = $this->assertAssignable($plan, $allowUnassignable);
        $interval = $this->resolveInterval($plan, $version, $options['billing_interval'] ?? null);
        $customLimits = $this->normalizeCustomLimits($plan, $options['custom_limits'] ?? null);
        $price = $this->resolvePrice($plan, $version, $interval, $options['contracted_price'] ?? null);

        $scheduledFor = isset($options['scheduled_for']) && $options['scheduled_for'] !== null
            ? Carbon::parse($options['scheduled_for'])
            : null;
        if ($scheduledFor && $scheduledFor->isFuture()) {
            return $this->schedule($tenant, $plan, $version, $interval, $price, $customLimits, $scheduledFor, $options, $actor);
        }

        $impact = $this->impact->compare($tenant, $version, $customLimits);
        if ($impact['requires_confirmation'] && empty($options['confirm_impact'])) {
            throw SubscriptionException::requiresConfirmation($impact);
        }

        $source = (string) ($options['source'] ?? TenantSubscription::SOURCE_ASSIGNMENT);
        $reason = $options['reason'] ?? null;
        $actorId = $actor?->id;
        $actorRole = SubscriptionAuditService::actorRole($actor) ?? ($actor ? null : 'system');

        try {
            $subscription = $this->commitChange($tenant, $plan, $version, $interval, $price, $customLimits, $options, $source, $reason, $actorId, $actorRole, $impact);
        } finally {
            $this->resolver->forget((int) $tenant->getKey());
        }

        $tenant->refresh();
        if ($options['notify'] ?? true) {
            $this->notify($tenant);
        }

        return $subscription->fresh(['plan', 'version']);
    }

    /**
     * @param  array<string, int|null>  $customLimits
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $impact
     */
    private function commitChange(Tenant $tenant, Plan $plan, PlanVersion $version, string $interval, ?string $price, array $customLimits, array $options, string $source, ?string $reason, ?int $actorId, ?string $actorRole, array $impact): TenantSubscription
    {
        return DB::transaction(function () use ($tenant, $plan, $version, $interval, $price, $customLimits, $options, $source, $reason, $actorId, $actorRole, $impact) {
            // Plan then tenant: same order as PlanService::update so edit vs assign cannot deadlock.
            Plan::withTrashed()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            /** @var Tenant $locked */
            $locked = Tenant::withTrashed()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($locked);

            $previous = TenantSubscription::query()->forTenant((int) $locked->id)->current()->first();

            // Only one PENDING row may exist: an immediate change cancels any scheduled change.
            TenantSubscription::query()->forTenant((int) $locked->id)
                ->where('record_status', TenantSubscription::RECORD_PENDING)
                ->update(['record_status' => TenantSubscription::RECORD_CANCELLED, 'superseded_at' => now()]);

            $subscription = TenantSubscription::query()->create([
                'tenant_id' => $locked->id,
                'plan_id' => $plan->id,
                'plan_version_id' => $version->id,
                'record_status' => TenantSubscription::RECORD_PENDING,
                'billing_interval' => $interval,
                'currency_code' => $version->currency_code ?: 'INR',
                'contracted_price' => $price,
                'custom_limits' => $customLimits ?: null,
                'starts_at' => now(),
                'source' => $source,
                'assigned_by' => $actorId,
                'reason' => $reason,
            ]);

            if ($previous) {
                $previous->forceFill([
                    'record_status' => TenantSubscription::RECORD_SUPERSEDED,
                    'superseded_at' => now(),
                    'superseded_by_id' => $subscription->id,
                ])->save();
            }
            $subscription->forceFill(['record_status' => TenantSubscription::RECORD_CURRENT])->save();

            $revokedIds = $this->impact->overridesRevokedOnChange((int) $locked->id);
            if ($revokedIds !== []) {
                TenantEntitlementOverride::query()->whereIn('id', $revokedIds)->update([
                    'revoked_at' => now(),
                    'revoked_by' => $actorId,
                    'revoke_reason' => 'Superseded by plan change',
                ]);
            }

            // Uncached: nothing inside this transaction may populate the entitlement cache.
            $resolved = ResolvedEntitlements::fromArray((array) $this->resolver->resolveFromSubscription((int) $locked->id));

            $update = $this->legacyMirror($resolved) + [
                'plan' => $plan->key,
                'updated_by' => $actorId,
            ];
            if (! empty($options['duration_months'])) {
                $update['subscription_ends_at'] = now()->addMonths((int) $options['duration_months']);
            }
            if (! empty($options['start_trial'])) {
                $trialEnds = $this->trialEndsAt($version);
                if ($trialEnds) {
                    $update['trial_ends_at'] = $trialEnds;
                }
            }
            if (! empty($options['clear_suspension'])) {
                $update['subscription_suspended_at'] = null;
            }

            $locked->forceFill($update)->save();
            $locked->refresh();

            $this->audit->tenant((int) $locked->id, 'plan_changed', $before, $this->snapshot($locked) + [
                'impact' => [
                    'features_lost' => array_column($impact['features_lost'], 'code'),
                    'features_gained' => array_column($impact['features_gained'], 'code'),
                    'over_limit' => array_column($impact['over_limit'], 'code'),
                ],
            ], strtolower($source), $reason, $actorId, $actorRole);

            TenantCacheVersion::bump((int) $locked->id);

            return $subscription;
        });
    }

    public function applyDuePending(TenantSubscription $pending): ?TenantSubscription
    {
        if ($pending->record_status !== TenantSubscription::RECORD_PENDING) {
            return null;
        }
        $tenant = Tenant::withTrashed()->find($pending->tenant_id);
        $plan = Plan::withTrashed()->find($pending->plan_id);
        if (! $tenant || ! $plan) {
            return null;
        }

        try {
            return $this->assign($tenant, $plan, [
                'billing_interval' => $pending->billing_interval,
                'contracted_price' => $pending->contracted_price,
                'custom_limits' => $pending->custom_limits,
                'reason' => $pending->reason,
                'source' => TenantSubscription::SOURCE_SCHEDULED,
                'confirm_impact' => true,
                'honor_accepted_schedule' => true,
            ], $pending->assigned_by ? User::query()->find($pending->assigned_by) : null);
        } catch (SubscriptionException $e) {
            $pending->forceFill(['record_status' => TenantSubscription::RECORD_CANCELLED, 'superseded_at' => now()])->save();
            $this->audit->tenant((int) $tenant->id, 'scheduled_change_cancelled', ['subscription_id' => $pending->id], [
                'error_code' => $e->errorCode,
            ], 'scheduler', $e->getMessage(), null, 'system');

            return null;
        }
    }

    /**
     * Move tenants pinned to older versions of a plan onto its ACTIVE version, one tenant per
     * transaction through assign(). Tenants whose change would lose features or put them over a
     * limit are skipped unless confirm_impact is set.
     *
     * @param  array{from_version_id?: int|null, tenant_ids?: list<int>|null, limit?: int|null, keep_contracted_price?: bool, confirm_impact?: bool, dry_run?: bool, reason: string}  $options
     * @return array<string, mixed>
     */
    public function migrateVersionTenants(PlanVersion $target, array $options, User $actor): array
    {
        if ($target->status !== PlanVersion::STATUS_ACTIVE) {
            throw SubscriptionException::versionNotActive('Tenants can only be moved to the active version of a plan.');
        }
        $plan = Plan::withTrashed()->findOrFail($target->plan_id);
        $this->assertAssignable($plan, true);

        $query = TenantSubscription::query()
            ->where('plan_id', $plan->id)
            ->where('plan_version_id', '!=', $target->id)
            ->current()
            ->when($options['from_version_id'] ?? null, fn ($q, $id) => $q->where('plan_version_id', (int) $id))
            ->when($options['tenant_ids'] ?? null, fn ($q, $ids) => $q->whereIn('tenant_id', array_map('intval', $ids)))
            ->orderBy('tenant_id');

        $eligible = (clone $query)->count();
        $batch = $query->limit((int) ($options['limit'] ?? 100))->get();
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $keepPrice = (bool) ($options['keep_contracted_price'] ?? false) || $plan->isCustomPriced();

        $migrated = [];
        $skipped = [];
        $previewed = [];
        foreach ($batch as $current) {
            $tenant = Tenant::query()->find($current->tenant_id);
            if (! $tenant) {
                continue;
            }
            $interval = in_array($current->billing_interval, (array) $target->billing_intervals, true) ? $current->billing_interval : null;

            if ($dryRun) {
                $impact = $this->impact->compare($tenant, $target, $plan->isCustomPriced() ? (array) ($current->custom_limits ?? []) : []);
                $previewed[] = [
                    'tenant_id' => (int) $tenant->id,
                    'tenant_name' => $tenant->name,
                    'from_version_id' => (int) $current->plan_version_id,
                    'requires_confirmation' => $impact['requires_confirmation'],
                    'features_lost' => array_column($impact['features_lost'], 'code'),
                    'over_limit' => array_column($impact['over_limit'], 'code'),
                ];

                continue;
            }

            try {
                $this->assign($tenant, $plan, [
                    'billing_interval' => $interval,
                    'contracted_price' => $keepPrice ? $current->contracted_price : null,
                    'custom_limits' => $plan->isCustomPriced() ? $current->custom_limits : null,
                    'reason' => $options['reason'],
                    'source' => TenantSubscription::SOURCE_VERSION_MIGRATION,
                    'confirm_impact' => (bool) ($options['confirm_impact'] ?? false),
                    'allow_unassignable' => true,
                ], $actor);
                $migrated[] = (int) $tenant->id;
            } catch (SubscriptionException $e) {
                $skipped[] = ['tenant_id' => (int) $tenant->id, 'tenant_name' => $tenant->name, 'code' => $e->errorCode, 'message' => $e->getMessage()];
            }
        }

        if (! $dryRun) {
            $this->audit->catalog('plan_version', (int) $target->id, 'plan_version_tenants_migrated', null, [
                'migrated' => count($migrated),
                'skipped' => count($skipped),
                'from_version_id' => $options['from_version_id'] ?? null,
                'keep_contracted_price' => $keepPrice,
            ], $actor, $options['reason']);
        }

        return [
            'dry_run' => $dryRun,
            'eligible' => $eligible,
            'processed' => $batch->count(),
            'migrated' => $migrated,
            'skipped' => $skipped,
            'preview' => $previewed,
            'remaining' => $dryRun ? $eligible : max(0, $eligible - count($migrated)),
        ];
    }

    public function cancelPending(Tenant $tenant, ?User $actor = null, ?string $reason = null): bool
    {
        $pending = TenantSubscription::query()->forTenant((int) $tenant->id)
            ->where('record_status', TenantSubscription::RECORD_PENDING)
            ->first();
        if (! $pending) {
            return false;
        }
        $pending->forceFill(['record_status' => TenantSubscription::RECORD_CANCELLED, 'superseded_at' => now()])->save();
        $this->audit->tenant((int) $tenant->id, 'scheduled_change_cancelled', ['subscription_id' => $pending->id], [], 'admin_ui', $reason, $actor?->id, SubscriptionAuditService::actorRole($actor));

        return true;
    }

    // ── TenantPlanAssigner (Tenants module adapters) ─────────────────────────

    public function assignInitialPlan(Tenant $tenant, ?string $planKey, ?int $actorId, ?string $actorRole): Tenant
    {
        $plan = $planKey
            ? Plan::query()->assignable()->where('key', $planKey)->first()
            : $this->policies->defaultPlan();

        if ($planKey && ! $plan) {
            throw SubscriptionException::planNotAvailable();
        }
        if (! $plan) {
            return $tenant;
        }

        $this->assign($tenant, $plan, [
            'start_trial' => $tenant->trial_ends_at === null && (bool) $this->policies->get('trial.allow_trial_on_assignment', true),
            'reason' => 'Initial plan',
            'source' => $planKey ? TenantSubscription::SOURCE_ASSIGNMENT : TenantSubscription::SOURCE_DEFAULT,
            'confirm_impact' => true,
            'notify' => false,
        ], $actorId ? User::query()->find($actorId) : null);

        return $tenant->refresh();
    }

    public function applyPlanByKey(Tenant $tenant, string $planKey, array $options, ?int $actorId, ?string $actorRole): Tenant
    {
        $plan = Plan::query()->assignable()->where('key', $planKey)->first();
        if (! $plan) {
            throw SubscriptionException::planNotAvailable();
        }

        $this->assign($tenant, $plan, [
            'duration_months' => $options['subscription_duration_months'] ?? 12,
            'reason' => $options['reason'] ?? null,
            'source' => (string) ($options['source'] ?? 'admin_ui'),
            'confirm_impact' => (bool) ($options['allow_downgrade_non_free'] ?? false) || ! $this->isTierDowngrade($tenant, $plan),
            'start_trial' => $plan->pricing_type === Plan::PRICING_FREE,
            'clear_suspension' => true,
        ], $actorId ? User::query()->find($actorId) : null);

        return $tenant->refresh();
    }

    /**
     * Legacy upgrade endpoint semantics: moving off a grandfathered plan or to an equal/higher
     * catalog tier is an upgrade; only a lower catalog tier needs explicit confirmation.
     */
    private function isTierDowngrade(Tenant $tenant, Plan $target): bool
    {
        $current = TenantSubscription::query()->forTenant((int) $tenant->getKey())->current()->with('plan')->first()?->plan;
        if (! $current || $current->is_legacy) {
            return false;
        }

        return (int) $target->display_order < (int) $current->display_order;
    }

    public function assignablePlanKeys(): array
    {
        return Plan::query()->assignable()->orderBy('display_order')->pluck('key')->map(static fn ($k) => (string) $k)->all();
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    public function assertAssignable(Plan $plan, bool $allowUnassignable = false): PlanVersion
    {
        if (! $plan->code || $plan->trashed() || $plan->isArchived() || $plan->status !== Plan::STATUS_ACTIVE) {
            throw SubscriptionException::planNotAvailable();
        }
        if (! $allowUnassignable && (! $plan->is_assignable || $plan->is_legacy)) {
            throw SubscriptionException::planNotAvailable('This plan is no longer offered.');
        }

        $version = PlanVersion::query()->where('plan_id', $plan->id)->where('status', PlanVersion::STATUS_ACTIVE)->first();
        if (! $version) {
            throw SubscriptionException::versionNotActive();
        }

        return $version;
    }

    private function resolveInterval(Plan $plan, PlanVersion $version, ?string $requested): string
    {
        $allowed = array_values(array_filter((array) $version->billing_intervals, static fn ($i) => in_array($i, PlanVersion::INTERVALS, true)));
        if ($allowed === []) {
            $allowed = $plan->isCustomPriced() ? [PlanVersion::INTERVAL_CUSTOM] : [PlanVersion::INTERVAL_MONTHLY];
        }
        $interval = $requested ? strtoupper($requested) : $allowed[0];
        if (! in_array($interval, $allowed, true)) {
            throw SubscriptionException::changeNotAllowed('This billing interval is not offered for the selected plan.', ['allowed_intervals' => $allowed]);
        }

        return $interval;
    }

    private function resolvePrice(Plan $plan, PlanVersion $version, string $interval, mixed $requested): ?string
    {
        if ($requested !== null && $requested !== '') {
            if (! is_numeric($requested) || (float) $requested < 0) {
                throw SubscriptionException::changeNotAllowed('The contracted price must be zero or more.');
            }

            return MoneyMath::normalize((string) $requested);
        }

        if ($plan->pricing_type === Plan::PRICING_FREE) {
            return '0.00';
        }
        if ($plan->isCustomPriced()) {
            return null;
        }

        $price = match ($interval) {
            PlanVersion::INTERVAL_ANNUAL => $version->annual_price,
            PlanVersion::INTERVAL_MONTHLY => $version->monthly_price,
            default => null,
        };

        return $price === null ? null : MoneyMath::normalize((string) $price);
    }

    /**
     * @param  array<string, mixed>|null  $customLimits
     * @return array<string, int|null>
     */
    private function normalizeCustomLimits(Plan $plan, ?array $customLimits): array
    {
        if (! $customLimits) {
            return [];
        }
        if (! $plan->isCustomPriced()) {
            throw SubscriptionException::changeNotAllowed('Custom limits are only available on custom-priced plans. Use an entitlement override instead.');
        }

        $normalized = [];
        foreach ($customLimits as $code => $value) {
            $code = strtoupper((string) $code);
            $feature = $this->catalog->feature($code);
            if (! $feature || ! in_array($feature['type'], ['LIMIT', 'QUOTA', 'USAGE'], true)) {
                throw SubscriptionException::changeNotAllowed("{$code} is not a limit feature.");
            }
            if ($value !== null && (! is_numeric($value) || (int) $value < 0)) {
                throw SubscriptionException::changeNotAllowed("{$code} must be zero or more, or empty for unlimited.");
            }
            $normalized[$code] = $value === null ? null : (int) $value;
        }

        return $normalized;
    }

    private function trialEndsAt(PlanVersion $version): ?Carbon
    {
        $days = (int) ($version->trial_days ?? 0);
        if ($days <= 0) {
            return null;
        }
        $max = (int) $this->policies->get('trial.max_trial_days', 90);

        return now()->addDays($max > 0 ? min($days, $max) : $days);
    }

    /**
     * @param  array<string, int|null>  $customLimits
     * @param  array<string, mixed>  $options
     */
    private function schedule(Tenant $tenant, Plan $plan, PlanVersion $version, string $interval, ?string $price, array $customLimits, Carbon $at, array $options, ?User $actor): TenantSubscription
    {
        return DB::transaction(function () use ($tenant, $plan, $version, $interval, $price, $customLimits, $at, $options, $actor) {
            // Plan then tenant: same order as PlanService::update so edit vs assign cannot deadlock.
            Plan::withTrashed()->whereKey($plan->id)->lockForUpdate()->first(['id']);
            Tenant::withTrashed()->whereKey($tenant->getKey())->lockForUpdate()->first(['id']);

            TenantSubscription::query()->forTenant((int) $tenant->id)
                ->where('record_status', TenantSubscription::RECORD_PENDING)
                ->update(['record_status' => TenantSubscription::RECORD_CANCELLED, 'superseded_at' => now()]);

            $pending = TenantSubscription::query()->create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'plan_version_id' => $version->id,
                'record_status' => TenantSubscription::RECORD_PENDING,
                'billing_interval' => $interval,
                'currency_code' => $version->currency_code ?: 'INR',
                'contracted_price' => $price,
                'custom_limits' => $customLimits ?: null,
                'scheduled_for' => $at,
                'source' => (string) ($options['source'] ?? TenantSubscription::SOURCE_ASSIGNMENT),
                'assigned_by' => $actor?->id,
                'reason' => $options['reason'] ?? null,
            ]);

            $this->audit->tenant((int) $tenant->id, 'plan_change_scheduled', ['plan' => $tenant->plan], [
                'plan_code' => $plan->code,
                'plan_version_id' => $version->id,
                'scheduled_for' => $at->toIso8601String(),
            ], 'admin_ui', $options['reason'] ?? null, $actor?->id, SubscriptionAuditService::actorRole($actor));

            return $pending;
        });
    }

    /**
     * Legacy columns kept in sync so switching the engine back to "legacy" preserves access.
     *
     * @return array<string, mixed>
     */
    private function legacyMirror(ResolvedEntitlements $resolved): array
    {
        $legacyKeys = [];
        foreach ($this->catalog->snapshot()['legacy'] as $legacyKey => $code) {
            if ($resolved->allows($code)) {
                $legacyKeys[] = $legacyKey;
            }
        }

        return [
            // An empty list means "allow donations" in legacy rules, so mark catalog-managed lists.
            'features' => $legacyKeys === [] ? [self::MIRROR_MARKER] : $legacyKeys,
            'max_users' => LegacyColumns::fromLimit($resolved->limit('STAFF_USER_LIMIT')),
            'max_storage_mb' => LegacyColumns::fromLimit($resolved->limit('STORAGE_MB')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Tenant $tenant): array
    {
        $current = TenantSubscription::query()->forTenant((int) $tenant->id)->current()->with(['plan', 'version'])->first();

        return [
            'plan' => $tenant->plan,
            'plan_code' => $current?->plan?->code,
            'plan_version_id' => $current?->plan_version_id,
            'plan_version_number' => $current?->version?->version_number,
            'billing_interval' => $current?->billing_interval,
            'contracted_price' => $current?->contracted_price,
            'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
            'subscription_ends_at' => $tenant->subscription_ends_at?->toIso8601String(),
            'subscription_suspended_at' => $tenant->subscription_suspended_at?->toIso8601String(),
            'status' => $this->lifecycle->resolveStatus($tenant),
        ];
    }

    private function notify(Tenant $tenant): void
    {
        try {
            app(SubscriptionLifecycleNotificationPublisher::class)->notifyTransition($tenant, 'plan_changed');
        } catch (Throwable $e) {
            Log::warning('Subscription plan change notification failed', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
