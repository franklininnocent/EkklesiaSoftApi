<?php

namespace Modules\Tenants\Services;

use App\Support\UserFacingDate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Tenants\Contracts\TenantPlanAssigner;
use Modules\Tenants\Models\SubscriptionDurationOption;
use Modules\Tenants\Models\SubscriptionPlan;
use Modules\Tenants\Models\SubscriptionSettings;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Models\TenantSubscriptionAudit;
use Modules\Tenants\Support\TenantCacheVersion;
use RuntimeException;

/**
 * Authoritative subscription domain service (R1 — no payments).
 */
class SubscriptionService
{
    public const STATUS_TRIAL = 'TRIAL';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_LIFETIME = 'LIFETIME';

    public const STATUS_EXPIRING = 'EXPIRING';

    public const STATUS_GRACE_PERIOD = 'GRACE_PERIOD';

    public const STATUS_EXPIRED = 'EXPIRED';

    public const STATUS_SUSPENDED = 'SUSPENDED';

    public const ACCESS_MODE_FULL = 'full';

    public const ACCESS_MODE_READ_ONLY = 'read_only';

    public const ACCESS_MODE_VIEW_ONLY = 'view_only';

    public const ACCESS_MODE_LOCKED = 'locked';

    public const EFFECTIVE_VIEW_ONLY = 'VIEW_ONLY';

    public const EFFECTIVE_LOCKED = 'LOCKED';

    public const CODE_SUBSCRIPTION_READ_ONLY = 'SUBSCRIPTION_READ_ONLY';

    public const CODE_SUBSCRIPTION_VIEW_ONLY = 'SUBSCRIPTION_VIEW_ONLY';

    public const CODE_SUBSCRIPTION_LOCKED = 'SUBSCRIPTION_LOCKED';

    public const WRITE_POLICY_LEGACY = 'legacy';

    public const WRITE_POLICY_READ_ONLY_WHEN_EXPIRED = 'read_only_when_expired';

    /**
     * Modules soft-gated when subscription is EXPIRED or SUSPENDED.
     *
     * @return list<string>
     */
    public function gatedModules(): array
    {
        return array_values(config('tenants.subscription.gated_modules', [
            'donations',
            'ministries_associations',
            'mass_intentions',
            'groups',
            'messaging',
            'events',
        ]));
    }

    public function getSettings(): SubscriptionSettings
    {
        return SubscriptionSettings::current();
    }

    /**
     * @param  array{grace_period_days?: int, expiring_warning_days?: int, view_only_allowance_days?: int}  $data
     */
    public function updateSettings(array $data, ?int $actorId = null, ?string $actorRole = null): SubscriptionSettings
    {
        $settings = $this->getSettings();
        $before = $settings->only(['grace_period_days', 'expiring_warning_days', 'view_only_allowance_days']);

        $settings->fill([
            'grace_period_days' => $data['grace_period_days'] ?? $settings->grace_period_days,
            'expiring_warning_days' => $data['expiring_warning_days'] ?? $settings->expiring_warning_days,
            'view_only_allowance_days' => $data['view_only_allowance_days'] ?? $settings->view_only_allowance_days,
        ]);
        $settings->save();

        $this->auditPlatformSettings($before, $settings->only(['grace_period_days', 'expiring_warning_days', 'view_only_allowance_days']), $actorId, $actorRole);

        return $settings->fresh();
    }

    public function usesViewOnlyLockedEnforcement(): bool
    {
        return (bool) config('tenants.subscription.view_only_locked_enforcement', false);
    }

    public function usesReadOnlyWhenExpiredPolicy(): bool
    {
        return config('tenants.subscription.write_policy', self::WRITE_POLICY_LEGACY)
            === self::WRITE_POLICY_READ_ONLY_WHEN_EXPIRED;
    }

    public function accessMode(Tenant $tenant): string
    {
        return $this->evaluateAccess($tenant)['access_mode'];
    }

    public function isWriteAllowed(Tenant $tenant): bool
    {
        if (! $this->usesReadOnlyWhenExpiredPolicy()) {
            return true;
        }

        return $this->evaluateAccess($tenant)['access_mode'] === self::ACCESS_MODE_FULL;
    }

    public function isExportAllowed(Tenant $tenant): bool
    {
        if (! $this->usesReadOnlyWhenExpiredPolicy()) {
            return true;
        }

        if (! $this->usesViewOnlyLockedEnforcement()) {
            return $this->isWriteAllowed($tenant);
        }

        return $this->evaluateAccess($tenant)['access_mode'] === self::ACCESS_MODE_FULL;
    }

    public function isParishDataReadAllowed(Tenant $tenant): bool
    {
        if (! $this->usesViewOnlyLockedEnforcement()) {
            return true;
        }

        return $this->evaluateAccess($tenant)['access_mode'] !== self::ACCESS_MODE_LOCKED;
    }

    /**
     * Authoritative effective subscription access for middleware, snapshots, and jobs.
     *
     * @return array{
     *     access_mode: string,
     *     effective_status: string,
     *     stored_status: string,
     *     subscription_ends_at: ?Carbon,
     *     grace_ends_at: ?Carbon,
     *     view_only_ends_at: ?Carbon,
     *     grandfather_until: ?Carbon,
     *     grace_period_days: int,
     *     view_only_allowance_days: int,
     *     grace_period_inherited: bool,
     *     view_only_allowance_inherited: bool,
     *     renewal_available: bool,
     * }
     */
    public function evaluateAccess(Tenant $tenant, bool $ignoreGrandfather = false, bool $assumeLockedEnforcement = false): array
    {
        $storedStatus = $this->resolveStatus($tenant);
        $endsAt = $tenant->subscription_ends_at;
        $graceDays = $this->effectiveGracePeriodDays($tenant);
        $viewOnlyDays = $this->effectiveViewOnlyAllowanceDays($tenant);
        $graceEndsAt = $endsAt && $graceDays > 0 ? $endsAt->copy()->addDays($graceDays) : ($endsAt?->copy());
        $viewOnlyEndsAt = null;
        if ($graceEndsAt && $viewOnlyDays > 0) {
            $viewOnlyEndsAt = $graceEndsAt->copy()->addDays($viewOnlyDays);
        }

        $grandfatherUntil = $tenant->view_only_grandfather_until;
        $renewalAvailable = (int) $tenant->active === 1 && empty($tenant->subscription_suspended_at);

        if (! empty($tenant->subscription_suspended_at)) {
            return $this->accessResult(
                self::ACCESS_MODE_READ_ONLY,
                self::STATUS_SUSPENDED,
                $storedStatus,
                $endsAt,
                $graceEndsAt,
                $viewOnlyEndsAt,
                $grandfatherUntil,
                $graceDays,
                $viewOnlyDays,
                $tenant,
                $renewalAvailable
            );
        }

        if ((int) $tenant->active !== 1) {
            return $this->accessResult(
                self::ACCESS_MODE_READ_ONLY,
                'INACTIVE',
                $storedStatus,
                $endsAt,
                $graceEndsAt,
                $viewOnlyEndsAt,
                $grandfatherUntil,
                $graceDays,
                $viewOnlyDays,
                $tenant,
                false
            );
        }

        if ($tenant->trial_ends_at && $tenant->trial_ends_at->isFuture()) {
            return $this->accessResult(
                self::ACCESS_MODE_FULL,
                self::STATUS_TRIAL,
                $storedStatus,
                $endsAt,
                $graceEndsAt,
                $viewOnlyEndsAt,
                $grandfatherUntil,
                $graceDays,
                $viewOnlyDays,
                $tenant,
                $renewalAvailable
            );
        }

        if ($endsAt === null) {
            return $this->accessResult(
                self::ACCESS_MODE_FULL,
                self::STATUS_LIFETIME,
                $storedStatus,
                $endsAt,
                $graceEndsAt,
                $viewOnlyEndsAt,
                $grandfatherUntil,
                $graceDays,
                $viewOnlyDays,
                $tenant,
                $renewalAvailable
            );
        }

        $now = now();

        if ($endsAt->isFuture()) {
            $effective = $storedStatus === self::STATUS_EXPIRING ? self::STATUS_EXPIRING : self::STATUS_ACTIVE;

            return $this->accessResult(
                self::ACCESS_MODE_FULL,
                $effective,
                $storedStatus,
                $endsAt,
                $graceEndsAt,
                $viewOnlyEndsAt,
                $grandfatherUntil,
                $graceDays,
                $viewOnlyDays,
                $tenant,
                $renewalAvailable
            );
        }

        if ($graceDays > 0 && $now->lte($endsAt->copy()->addDays($graceDays))) {
            return $this->accessResult(
                self::ACCESS_MODE_FULL,
                self::STATUS_GRACE_PERIOD,
                $storedStatus,
                $endsAt,
                $graceEndsAt,
                $viewOnlyEndsAt,
                $grandfatherUntil,
                $graceDays,
                $viewOnlyDays,
                $tenant,
                $renewalAvailable
            );
        }

        $inGrandfather = ! $ignoreGrandfather
            && $grandfatherUntil !== null
            && $now->lte($grandfatherUntil);

        $inViewOnlyWindow = $viewOnlyDays > 0 && $viewOnlyEndsAt && $now->lte($viewOnlyEndsAt);

        if ($inGrandfather || ($inViewOnlyWindow && $this->usesViewOnlyLockedEnforcement())) {
            return $this->accessResult(
                self::ACCESS_MODE_VIEW_ONLY,
                self::EFFECTIVE_VIEW_ONLY,
                $storedStatus,
                $endsAt,
                $graceEndsAt,
                $inGrandfather ? $grandfatherUntil : $viewOnlyEndsAt,
                $grandfatherUntil,
                $graceDays,
                $viewOnlyDays,
                $tenant,
                $renewalAvailable
            );
        }

        if ($this->usesViewOnlyLockedEnforcement() || $assumeLockedEnforcement) {
            return $this->accessResult(
                self::ACCESS_MODE_LOCKED,
                self::EFFECTIVE_LOCKED,
                $storedStatus,
                $endsAt,
                $graceEndsAt,
                $viewOnlyEndsAt,
                $grandfatherUntil,
                $graceDays,
                $viewOnlyDays,
                $tenant,
                $renewalAvailable
            );
        }

        return $this->accessResult(
            self::ACCESS_MODE_READ_ONLY,
            self::STATUS_EXPIRED,
            $storedStatus,
            $endsAt,
            $graceEndsAt,
            $viewOnlyEndsAt,
            $grandfatherUntil,
            $graceDays,
            $viewOnlyDays,
            $tenant,
            $renewalAvailable
        );
    }

    public function effectiveGracePeriodDays(Tenant $tenant): int
    {
        if ($tenant->custom_grace_period_days !== null) {
            return max(0, (int) $tenant->custom_grace_period_days);
        }

        return max(0, (int) $this->getSettings()->grace_period_days);
    }

    public function effectiveViewOnlyAllowanceDays(Tenant $tenant): int
    {
        if ($tenant->custom_view_only_allowance_days !== null) {
            return max(0, (int) $tenant->custom_view_only_allowance_days);
        }

        $settings = $this->getSettings();
        $fromDb = $settings->view_only_allowance_days ?? null;

        return max(0, (int) ($fromDb ?? config('tenants.subscription.view_only_allowance_days', 90)));
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAccessPolicyPayload(Tenant $tenant): array
    {
        $access = $this->evaluateAccess($tenant);

        return [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
            'stored_status' => $access['stored_status'],
            'effective_status' => $access['effective_status'],
            'access_mode' => $access['access_mode'],
            'subscription_ends_at' => $access['subscription_ends_at']?->toIso8601String(),
            'grace_ends_at' => $access['grace_ends_at']?->toIso8601String(),
            'view_only_starts_at' => $access['grace_ends_at']?->toIso8601String(),
            'view_only_ends_at' => $access['view_only_ends_at']?->toIso8601String(),
            'grandfather_until' => $access['grandfather_until']?->toIso8601String(),
            'grace_period_days' => $access['grace_period_days'],
            'view_only_allowance_days' => $access['view_only_allowance_days'],
            'grace_period_inherited' => $access['grace_period_inherited'],
            'view_only_allowance_inherited' => $access['view_only_allowance_inherited'],
            'custom_grace_period_days' => $tenant->custom_grace_period_days,
            'custom_view_only_allowance_days' => $tenant->custom_view_only_allowance_days,
            'renewal_available' => $access['renewal_available'],
        ];
    }

    /**
     * @param  array{custom_grace_period_days?: int|null, custom_view_only_allowance_days?: int|null}  $data
     */
    public function updateTenantAccessPolicy(Tenant $tenant, array $data, ?int $actorId = null, ?string $actorRole = null): Tenant
    {
        $before = [
            'custom_grace_period_days' => $tenant->custom_grace_period_days,
            'custom_view_only_allowance_days' => $tenant->custom_view_only_allowance_days,
        ];

        $update = [];
        if (array_key_exists('custom_grace_period_days', $data)) {
            $update['custom_grace_period_days'] = $data['custom_grace_period_days'];
        }
        if (array_key_exists('custom_view_only_allowance_days', $data)) {
            $update['custom_view_only_allowance_days'] = $data['custom_view_only_allowance_days'];
        }

        return DB::transaction(function () use ($tenant, $update, $before, $actorId, $actorRole) {
            $tenant->update($update);
            $tenant->refresh();
            $after = [
                'custom_grace_period_days' => $tenant->custom_grace_period_days,
                'custom_view_only_allowance_days' => $tenant->custom_view_only_allowance_days,
            ];
            $this->audit($tenant, 'access_policy_updated', $before, $after, $actorId, $actorRole, 'admin_ui', null);
            TenantCacheVersion::bump($tenant->id);

            return $tenant;
        });
    }

    public function setGrandfatherUntil(Tenant $tenant, ?Carbon $until, ?int $actorId = null): void
    {
        $before = ['view_only_grandfather_until' => $tenant->view_only_grandfather_until?->toIso8601String()];
        $tenant->update(['view_only_grandfather_until' => $until]);
        $tenant->refresh();
        $after = ['view_only_grandfather_until' => $tenant->view_only_grandfather_until?->toIso8601String()];
        $this->audit($tenant, 'view_only_grandfather_set', $before, $after, $actorId, 'system', 'migration', null);
    }

    public function resolveStatus(Tenant $tenant): string
    {
        if (! empty($tenant->subscription_suspended_at)) {
            return self::STATUS_SUSPENDED;
        }

        $now = now();

        if ($tenant->trial_ends_at && $tenant->trial_ends_at->isFuture()) {
            return self::STATUS_TRIAL;
        }

        $settings = $this->getSettings();
        $graceDays = $this->effectiveGracePeriodDays($tenant);
        $warningDays = max(0, (int) $settings->expiring_warning_days);

        $endsAt = $tenant->subscription_ends_at;

        if ($endsAt === null) {
            return self::STATUS_LIFETIME;
        }

        if ($endsAt->isFuture()) {
            if ($warningDays > 0 && $endsAt->lte($now->copy()->addDays($warningDays))) {
                return self::STATUS_EXPIRING;
            }

            return self::STATUS_ACTIVE;
        }

        // Past end date
        $graceEnds = $endsAt->copy()->addDays($graceDays);
        if ($graceDays > 0 && $now->lte($graceEnds)) {
            return self::STATUS_GRACE_PERIOD;
        }

        return self::STATUS_EXPIRED;
    }

    public function allowsGatedAccess(Tenant $tenant): bool
    {
        if ((int) $tenant->active !== 1) {
            return false;
        }

        if ($this->usesViewOnlyLockedEnforcement()) {
            $mode = $this->evaluateAccess($tenant)['access_mode'];

            return $mode !== self::ACCESS_MODE_LOCKED;
        }

        if ($this->usesReadOnlyWhenExpiredPolicy()) {
            return true;
        }

        $status = $this->resolveStatus($tenant);

        return ! in_array($status, [self::STATUS_EXPIRED, self::STATUS_SUSPENDED], true);
    }

    /**
     * Entitlement hierarchy for a gated module key (e.g. donations).
     *
     * @return array{allowed: bool, reason: string|null, status: string}
     */
    public function evaluateModuleAccess(Tenant $tenant, string $moduleKey): array
    {
        $status = $this->resolveStatus($tenant);

        if ((int) $tenant->active !== 1) {
            return ['allowed' => false, 'reason' => 'account_inactive', 'status' => $status];
        }

        if (
            ! $this->usesReadOnlyWhenExpiredPolicy()
            && in_array($status, [self::STATUS_EXPIRED, self::STATUS_SUSPENDED], true)
        ) {
            return ['allowed' => false, 'reason' => 'subscription_blocked', 'status' => $status];
        }

        if (in_array($moduleKey, $this->gatedModules(), true) && ! $tenant->hasFeature($moduleKey)) {
            // donations uses supportsDonations with empty features = allow; keep that nuance
            if ($moduleKey === 'donations') {
                if (! $tenant->supportsDonations()) {
                    return ['allowed' => false, 'reason' => 'feature_not_entitled', 'status' => $status];
                }
            } elseif ($moduleKey === 'ministries_associations') {
                if (! $tenant->supportsMinistriesAssociations()) {
                    return ['allowed' => false, 'reason' => 'feature_not_entitled', 'status' => $status];
                }
            } elseif ($moduleKey === 'mass_intentions') {
                if (! $tenant->supportsMassIntentions()) {
                    return ['allowed' => false, 'reason' => 'feature_not_entitled', 'status' => $status];
                }
            } else {
                return ['allowed' => false, 'reason' => 'feature_not_entitled', 'status' => $status];
            }
        }

        return ['allowed' => true, 'reason' => null, 'status' => $status];
    }

    public function assertModuleAccess(Tenant $tenant, string $moduleKey): void
    {
        $result = $this->evaluateModuleAccess($tenant, $moduleKey);
        if (! $result['allowed']) {
            throw new RuntimeException($result['reason'] ?? 'subscription_blocked');
        }
    }

    /**
     * Apply a DB plan to a tenant (admin grant / upgrade).
     *
     * @param  array{plan: string, subscription_duration_months?: int|null, allow_downgrade_non_free?: bool, reason?: string|null, source?: string}  $options
     */
    public function applyPlan(Tenant $tenant, array $options, ?int $actorId = null, ?string $actorRole = null): Tenant
    {
        $planKey = $options['plan'];
        $durationMonths = (int) ($options['subscription_duration_months'] ?? 12);
        $source = $options['source'] ?? 'admin_ui';
        $reason = $options['reason'] ?? null;

        if (app()->bound(TenantPlanAssigner::class)) {
            $this->assertDurationAllowed($durationMonths);

            return app(TenantPlanAssigner::class)->applyPlanByKey($tenant, (string) $planKey, $options, $actorId, $actorRole);
        }

        $plan = SubscriptionPlan::query()->where('key', $planKey)->where('active', true)->first();
        if (! $plan) {
            throw new RuntimeException('invalid_plan');
        }

        $this->assertDurationAllowed($durationMonths);

        $order = ['free' => 0, 'starter' => 1, 'standard' => 2, 'professional' => 3, 'enterprise' => 4];
        $newOrder = $order[$planKey] ?? 0;
        $oldOrder = $order[$tenant->plan] ?? 0;
        if ($newOrder < $oldOrder && $planKey !== 'free' && empty($options['allow_downgrade_non_free'])) {
            throw new RuntimeException('downgrade_not_allowed');
        }

        $before = $this->snapshot($tenant);

        $subscriptionEndsAt = now()->addMonths($durationMonths);
        $trialEndsAt = null;
        if ($planKey === 'free') {
            $trialDays = (int) config('tenants.trial_days', 30);
            $trialEndsAt = now()->addDays($trialDays);
        }

        $update = [
            'plan' => $planKey,
            'max_users' => $plan->max_users,
            'max_storage_mb' => $plan->max_storage_mb,
            'subscription_ends_at' => $subscriptionEndsAt,
            'features' => $plan->features ?? [],
            'subscription_suspended_at' => null,
            'updated_by' => $actorId,
        ];
        if ($trialEndsAt) {
            $update['trial_ends_at'] = $trialEndsAt;
        }

        return DB::transaction(function () use ($tenant, $update, $before, $actorId, $actorRole, $source, $reason) {
            $tenant->update($update);
            $tenant->refresh();
            $this->audit($tenant, 'plan_changed', $before, $this->snapshot($tenant), $actorId, $actorRole, $source, $reason);
            TenantCacheVersion::bump($tenant->id);
            $this->notifyLifecycle($tenant, 'plan_changed');

            return $tenant;
        });
    }

    /**
     * @param  array{duration_months?: int, reason?: string|null, source?: string}  $options
     */
    public function renew(Tenant $tenant, array $options = [], ?int $actorId = null, ?string $actorRole = null): Tenant
    {
        $durationMonths = (int) ($options['duration_months'] ?? 12);
        $this->assertDurationAllowed($durationMonths);
        $source = $options['source'] ?? 'admin_ui';
        $reason = $options['reason'] ?? null;

        $before = $this->snapshot($tenant);
        $currentEnd = $tenant->subscription_ends_at;

        if ($currentEnd && $currentEnd->isFuture()) {
            $newEnd = $currentEnd->copy()->addMonths($durationMonths);
        } else {
            $newEnd = now()->addMonths($durationMonths);
        }

        return DB::transaction(function () use ($tenant, $newEnd, $actorId, $before, $actorRole, $source, $reason) {
            $tenant->update([
                'subscription_ends_at' => $newEnd,
                'subscription_suspended_at' => null,
                'updated_by' => $actorId,
            ]);
            $tenant->refresh();
            $this->audit($tenant, 'subscription_extended', $before, $this->snapshot($tenant), $actorId, $actorRole, $source, $reason);
            TenantCacheVersion::bump($tenant->id);
            $this->notifyLifecycle($tenant, 'subscription_extended');

            return $tenant;
        });
    }

    public function suspend(Tenant $tenant, ?string $reason = null, ?int $actorId = null, ?string $actorRole = null, string $source = 'admin_ui'): Tenant
    {
        $before = $this->snapshot($tenant);

        return DB::transaction(function () use ($tenant, $before, $actorId, $actorRole, $source, $reason) {
            $tenant->update([
                'subscription_suspended_at' => now(),
                'updated_by' => $actorId,
            ]);
            $tenant->refresh();
            $this->audit($tenant, 'subscription_suspended', $before, $this->snapshot($tenant), $actorId, $actorRole, $source, $reason);
            TenantCacheVersion::bump($tenant->id);

            return $tenant;
        });
    }

    public function reactivate(Tenant $tenant, ?string $reason = null, ?int $actorId = null, ?string $actorRole = null, string $source = 'admin_ui'): Tenant
    {
        $before = $this->snapshot($tenant);

        return DB::transaction(function () use ($tenant, $before, $actorId, $actorRole, $source, $reason) {
            $tenant->update([
                'subscription_suspended_at' => null,
                'updated_by' => $actorId,
            ]);
            $tenant->refresh();
            $this->audit($tenant, 'subscription_reactivated', $before, $this->snapshot($tenant), $actorId, $actorRole, $source, $reason);
            TenantCacheVersion::bump($tenant->id);
            $this->notifyLifecycle($tenant, 'subscription_reactivated');

            return $tenant;
        });
    }

    /**
     * Lightweight access snapshot for any authenticated tenant user (nav / banners).
     *
     * @return array<string, mixed>
     */
    public function buildAccessSnapshot(Tenant $tenant): array
    {
        $settings = $this->getSettings();
        $access = $this->evaluateAccess($tenant);
        $status = $access['stored_status'];
        $endsAt = $tenant->subscription_ends_at;

        $daysUntilEnd = null;
        if ($endsAt) {
            $start = now()->startOfDay()->getTimestamp();
            $end = $endsAt->copy()->startOfDay()->getTimestamp();
            $daysUntilEnd = (int) floor(($end - $start) / 86400);
        }

        $accessMode = $access['access_mode'];
        $isReadOnly = $accessMode !== self::ACCESS_MODE_FULL;

        return [
            'status' => $status,
            'effective_status' => $access['effective_status'],
            'access_mode' => $accessMode,
            'is_read_only' => $isReadOnly,
            'is_view_only' => $accessMode === self::ACCESS_MODE_VIEW_ONLY,
            'is_locked' => $accessMode === self::ACCESS_MODE_LOCKED,
            'allows_gated_access' => $this->allowsGatedAccess($tenant),
            'gated_modules' => $this->gatedModules(),
            'subscription_ends_at' => $endsAt?->toIso8601String(),
            'grace_ends_at' => $access['grace_ends_at']?->toIso8601String(),
            'view_only_ends_at' => $access['view_only_ends_at']?->toIso8601String(),
            'grandfather_until' => $access['grandfather_until']?->toIso8601String(),
            'days_until_end' => $daysUntilEnd,
            'grace_period_days' => $access['grace_period_days'],
            'view_only_allowance_days' => $access['view_only_allowance_days'],
            'grace_period_inherited' => $access['grace_period_inherited'],
            'view_only_allowance_inherited' => $access['view_only_allowance_inherited'],
            'expiring_warning_days' => (int) $settings->expiring_warning_days,
            'renewal_available' => $access['renewal_available'],
            'write_policy' => config('tenants.subscription.write_policy', self::WRITE_POLICY_LEGACY),
            'view_only_locked_enforcement' => $this->usesViewOnlyLockedEnforcement(),
        ];
    }

    /**
     * Tenant-facing summary (auth-scoped).
     *
     * @return array<string, mixed>
     */
    public function buildSummary(Tenant $tenant): array
    {
        $plan = SubscriptionPlan::query()->where('key', $tenant->plan)->first();
        $access = $this->buildAccessSnapshot($tenant);

        return array_merge($access, [
            'plan_key' => $tenant->plan,
            'plan_name' => $plan?->name ?? $tenant->plan,
            'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
            'subscription_suspended_at' => $tenant->subscription_suspended_at?->toIso8601String(),
            'max_users' => $tenant->max_users,
            'max_storage_mb' => $tenant->max_storage_mb,
            'features' => $tenant->features ?? [],
            'currency' => config('tenants.default_settings.currency', 'INR'),
            'plan_price' => $plan ? (string) $plan->price : null,
        ]);
    }

    private function assertDurationAllowed(int $months): void
    {
        if ($months < 1 || $months > 120) {
            throw new RuntimeException('invalid_duration');
        }

        $exists = SubscriptionDurationOption::query()
            ->where('months', $months)
            ->where('active', true)
            ->exists();

        // If catalog empty, allow validated months range (bootstrap); else must match
        $catalogCount = SubscriptionDurationOption::query()->where('active', true)->count();
        if ($catalogCount > 0 && ! $exists) {
            throw new RuntimeException('duration_not_in_catalog');
        }
    }

    /**
     * Paginated subscription audit history for a tenant (Super Admin ops).
     *
     * @param  array{per_page?: int|string, page?: int|string, operation?: string|null}  $params
     * @return array{data: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function listAuditsForTenant(int $tenantId, array $params = []): array
    {
        $perPage = (int) ($params['per_page'] ?? 15);
        $perPage = max(1, min($perPage, 50));
        $operation = isset($params['operation']) ? trim((string) $params['operation']) : '';

        $query = TenantSubscriptionAudit::query()
            ->with(['actor:id,name,email'])
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($operation !== '') {
            $query->where('operation', $operation);
        }

        $paginator = $query->paginate($perPage);

        $data = collect($paginator->items())->map(function (TenantSubscriptionAudit $audit) {
            return $this->presentAudit($audit);
        })->values()->all();

        return [
            'data' => $data,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /**
     * Human-readable operation labels for known subscription audit operations.
     *
     * @return array<string, string>
     */
    public function auditOperationLabels(): array
    {
        return [
            'plan_changed' => 'Plan changed',
            'subscription_extended' => 'Subscription extended',
            'subscription_suspended' => 'Access suspended',
            'subscription_reactivated' => 'Access reactivated',
            'entered_expiring' => 'Entered expiring window',
            'entered_grace' => 'Entered grace period',
            'entered_expired' => 'Entered read-only (expired)',
            'entered_view_only' => 'Entered view-only mode',
            'entered_locked' => 'Entered locked (access restricted)',
            'access_policy_updated' => 'Access policy updated',
            'view_only_grandfather_set' => 'View-only grandfather deadline set',
            'subscription_settings_updated' => 'Platform subscription settings updated',
        ];
    }

    /**
     * Record a scheduler-driven lifecycle transition (idempotent side effect).
     */
    public function recordLifecycleTransition(Tenant $tenant, string $operation): void
    {
        $after = $this->snapshot($tenant);
        $before = ['status' => $after['status']];

        $this->audit($tenant, $operation, $before, $after, null, 'system', 'scheduler', null);
    }

    private function notifyLifecycle(Tenant $tenant, string $operation): void
    {
        try {
            app(SubscriptionLifecycleNotificationPublisher::class)->notifyTransition($tenant->fresh(), $operation);
        } catch (\Throwable $e) {
            Log::warning('Subscription lifecycle notification failed', [
                'tenant_id' => $tenant->id,
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAudit(TenantSubscriptionAudit $audit): array
    {
        $before = is_array($audit->before_state) ? $audit->before_state : [];
        $after = is_array($audit->after_state) ? $audit->after_state : [];
        $labels = $this->auditOperationLabels();

        return [
            'id' => $audit->id,
            'operation' => $audit->operation,
            'operation_label' => $labels[$audit->operation] ?? str_replace('_', ' ', (string) $audit->operation),
            'source' => $audit->source,
            'reason' => $audit->reason,
            'actor_id' => $audit->actor_id,
            'actor_name' => $audit->actor?->name,
            'actor_email' => $audit->actor?->email,
            'actor_role' => $audit->actor_role,
            'summary' => $this->buildAuditSummary($audit->operation, $before, $after),
            'before_state' => $before,
            'after_state' => $after,
            'correlation_id' => $audit->correlation_id,
            'created_at' => $audit->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function buildAuditSummary(string $operation, array $before, array $after): string
    {
        return match ($operation) {
            'plan_changed' => sprintf(
                'Plan %s → %s',
                $before['plan'] ?? '—',
                $after['plan'] ?? '—'
            ),
            'subscription_extended' => sprintf(
                'End date %s → %s',
                $this->formatAuditDate($before['subscription_ends_at'] ?? null),
                $this->formatAuditDate($after['subscription_ends_at'] ?? null)
            ),
            'subscription_suspended' => sprintf(
                'Status %s → %s',
                $before['status'] ?? '—',
                $after['status'] ?? 'SUSPENDED'
            ),
            'subscription_reactivated' => sprintf(
                'Status %s → %s',
                $before['status'] ?? 'SUSPENDED',
                $after['status'] ?? '—'
            ),
            default => $this->auditOperationLabels()[$operation]
                ?? ucfirst(str_replace('_', ' ', $operation)),
        };
    }

    private function formatAuditDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'none';
        }

        try {
            $label = UserFacingDate::formatDate((string) $value);

            return $label !== '' ? $label : (string) $value;
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Tenant $tenant): array
    {
        return [
            'plan' => $tenant->plan,
            'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
            'subscription_ends_at' => $tenant->subscription_ends_at?->toIso8601String(),
            'subscription_suspended_at' => $tenant->subscription_suspended_at?->toIso8601String(),
            'max_users' => $tenant->max_users,
            'max_storage_mb' => $tenant->max_storage_mb,
            'features' => $tenant->features ?? [],
            'status' => $this->resolveStatus($tenant),
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function auditPlatformSettings(array $before, array $after, ?int $actorId, ?string $actorRole): void
    {
        Log::info('Subscription settings updated', [
            'operation' => 'subscription_settings_updated',
            'before' => $before,
            'after' => $after,
            'updated_by' => $actorId,
            'role' => $actorRole,
        ]);
    }

    /**
     * @return array{
     *     access_mode: string,
     *     effective_status: string,
     *     stored_status: string,
     *     subscription_ends_at: ?Carbon,
     *     grace_ends_at: ?Carbon,
     *     view_only_ends_at: ?Carbon,
     *     grandfather_until: ?Carbon,
     *     grace_period_days: int,
     *     view_only_allowance_days: int,
     *     grace_period_inherited: bool,
     *     view_only_allowance_inherited: bool,
     *     renewal_available: bool,
     * }
     */
    private function accessResult(
        string $accessMode,
        string $effectiveStatus,
        string $storedStatus,
        ?Carbon $endsAt,
        ?Carbon $graceEndsAt,
        ?Carbon $viewOnlyEndsAt,
        ?Carbon $grandfatherUntil,
        int $graceDays,
        int $viewOnlyDays,
        Tenant $tenant,
        bool $renewalAvailable
    ): array {
        return [
            'access_mode' => $accessMode,
            'effective_status' => $effectiveStatus,
            'stored_status' => $storedStatus,
            'subscription_ends_at' => $endsAt,
            'grace_ends_at' => $graceEndsAt,
            'view_only_ends_at' => $viewOnlyEndsAt,
            'grandfather_until' => $grandfatherUntil,
            'grace_period_days' => $graceDays,
            'view_only_allowance_days' => $viewOnlyDays,
            'grace_period_inherited' => $tenant->custom_grace_period_days === null,
            'view_only_allowance_inherited' => $tenant->custom_view_only_allowance_days === null,
            'renewal_available' => $renewalAvailable,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function audit(
        Tenant $tenant,
        string $operation,
        array $before,
        array $after,
        ?int $actorId,
        ?string $actorRole,
        string $source,
        ?string $reason
    ): void {
        TenantSubscriptionAudit::query()->create([
            'tenant_id' => $tenant->id,
            'actor_id' => $actorId,
            'actor_role' => $actorRole,
            'operation' => $operation,
            'source' => $source,
            'reason' => $reason,
            'before_state' => $before,
            'after_state' => $after,
            'correlation_id' => request()->header('X-Request-Id') ?: request()->header('X-Correlation-Id'),
            'created_at' => now(),
        ]);
    }
}
