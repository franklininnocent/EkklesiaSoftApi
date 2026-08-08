<?php

namespace Modules\Tenants\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Tenants\Models\SubscriptionDurationOption;
use Modules\Tenants\Models\SubscriptionPlan;
use Modules\Tenants\Models\SubscriptionSettings;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Models\TenantSubscriptionAudit;
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
     * @param  array{grace_period_days?: int, expiring_warning_days?: int}  $data
     */
    public function updateSettings(array $data, ?int $actorId = null, ?string $actorRole = null): SubscriptionSettings
    {
        $settings = $this->getSettings();
        $before = $settings->only(['grace_period_days', 'expiring_warning_days']);

        $settings->fill([
            'grace_period_days' => $data['grace_period_days'] ?? $settings->grace_period_days,
            'expiring_warning_days' => $data['expiring_warning_days'] ?? $settings->expiring_warning_days,
        ]);
        $settings->save();

        Log::info('Subscription settings updated', [
            'before' => $before,
            'after' => $settings->only(['grace_period_days', 'expiring_warning_days']),
            'updated_by' => $actorId,
            'role' => $actorRole,
        ]);

        return $settings->fresh();
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
        $graceDays = max(0, (int) $settings->grace_period_days);
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

        if (in_array($status, [self::STATUS_EXPIRED, self::STATUS_SUSPENDED], true)) {
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

        $plan = SubscriptionPlan::query()->where('key', $planKey)->where('active', true)->first();
        if (! $plan) {
            throw new RuntimeException('invalid_plan');
        }

        $this->assertDurationAllowed($durationMonths);

        $order = ['free' => 0, 'basic' => 1, 'premium' => 2, 'enterprise' => 3];
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
        $status = $this->resolveStatus($tenant);
        $endsAt = $tenant->subscription_ends_at;
        $graceDays = max(0, (int) $settings->grace_period_days);
        $graceEndsAt = $endsAt && $graceDays > 0 ? $endsAt->copy()->addDays($graceDays) : null;

        $daysUntilEnd = null;
        if ($endsAt) {
            $start = now()->startOfDay()->getTimestamp();
            $end = $endsAt->copy()->startOfDay()->getTimestamp();
            $daysUntilEnd = (int) floor(($end - $start) / 86400);
        }

        return [
            'status' => $status,
            'allows_gated_access' => $this->allowsGatedAccess($tenant),
            'gated_modules' => $this->gatedModules(),
            'subscription_ends_at' => $endsAt?->toIso8601String(),
            'grace_ends_at' => $graceEndsAt?->toIso8601String(),
            'days_until_end' => $daysUntilEnd,
            'grace_period_days' => $graceDays,
            'expiring_warning_days' => (int) $settings->expiring_warning_days,
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
        ];
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
            return \Carbon\Carbon::parse((string) $value)->toFormattedDateString();
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
    private function audit(
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
