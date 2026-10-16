<?php

namespace Modules\Subscriptions\Services;

use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Models\TenantEntitlementOverride;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Subscriptions\Models\TenantUsageSnapshot;
use Modules\Subscriptions\Services\Catalog\TenantSubscriptionBackfiller;
use Modules\Subscriptions\Services\Entitlements\EntitlementCatalog;
use Modules\Subscriptions\Services\Entitlements\EntitlementResolver;
use Modules\Tenants\Models\Tenant;

/**
 * Explains what a plan change does before it happens: features gained/lost, limit changes
 * and records that would be over the new limit. Plan changes never delete data.
 */
class PlanImpactService
{
    private const LIVE_MEASUREMENT_CAP = 300;

    public function __construct(
        private readonly EntitlementResolver $resolver,
        private readonly EntitlementCatalog $catalog,
        private readonly UsageService $usage,
    ) {}

    /**
     * Backfill-only overrides that preserved legacy state; they are revoked on a plan change.
     *
     * @return list<int>
     */
    public function overridesRevokedOnChange(int $tenantId): array
    {
        return TenantEntitlementOverride::query()
            ->where('tenant_id', $tenantId)
            ->open()
            ->where('reason', TenantSubscriptionBackfiller::OVERRIDE_REASON)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, int|null>  $customLimits
     * @return array<string, mixed>
     */
    public function compare(Tenant $tenant, PlanVersion $target, array $customLimits = []): array
    {
        $current = $this->resolver->resolve($tenant);
        $revoked = $this->overridesRevokedOnChange((int) $tenant->getKey());
        $next = $this->resolver->simulate($tenant, $target, $customLimits, $revoked);

        $gained = [];
        $lost = [];
        $limits = [];
        $overLimit = [];

        foreach ($this->catalog->features() as $code => $feature) {
            $isNumeric = in_array($feature['type'], ['LIMIT', 'QUOTA', 'USAGE'], true);
            if ($feature['is_core']) {
                continue;
            }

            if (! $isNumeric) {
                $was = $current->allows($code);
                $will = $next->allows($code);
                if ($was && ! $will) {
                    $lost[] = ['code' => $code, 'name' => $feature['name']];
                } elseif (! $was && $will) {
                    $gained[] = ['code' => $code, 'name' => $feature['name']];
                }

                continue;
            }

            $from = $current->limit($code);
            $to = $next->limit($code);
            if ($from === $to) {
                continue;
            }
            $usage = $this->usage->isMeasurable($code) ? (int) $this->usage->current((int) $tenant->getKey(), $code) : null;
            $row = [
                'code' => $code,
                'name' => $feature['name'],
                'unit' => $feature['unit'],
                'from' => $from,
                'to' => $to,
                'usage' => $usage,
                'decrease' => $to !== null && ($from === null || $to < $from),
            ];
            $limits[] = $row;
            if ($to !== null && $usage !== null && $usage > $to) {
                $overLimit[] = $row + ['over_by' => $usage - $to];
            }
        }

        $isDowngrade = $lost !== [] || count(array_filter($limits, static fn (array $l) => $l['decrease'])) > 0;

        return [
            'current_plan' => $current->plan,
            'target_plan' => [
                'id' => $target->plan?->id,
                'code' => $target->plan?->code,
                'name' => $target->plan?->name,
                'version_id' => $target->id,
                'version_number' => $target->version_number,
            ],
            'features_gained' => $gained,
            'features_lost' => $lost,
            'limit_changes' => $limits,
            'over_limit' => $overLimit,
            'is_downgrade' => $isDowngrade,
            'requires_confirmation' => $lost !== [] || $overLimit !== [],
            'revoked_backfill_overrides' => count($revoked),
            'data_preserved' => true,
        ];
    }

    /**
     * What moving every tenant pinned to other versions of this plan onto $candidate would
     * change, grouped by the version they are on today. Plan-level entitlements only; tenant
     * overrides are kept across a version move and are shown in the per-tenant preview.
     *
     * @return array<string, mixed>
     */
    public function versionImpact(PlanVersion $candidate): array
    {
        $target = $this->versionMap($candidate);
        $pinned = TenantSubscription::query()
            ->where('plan_id', $candidate->plan_id)
            ->where('plan_version_id', '!=', $candidate->id)
            ->current()
            ->get(['tenant_id', 'plan_version_id'])
            ->groupBy('plan_version_id');

        $sources = PlanVersion::query()->whereIn('id', $pinned->keys())->with('entitlements')->get()->keyBy('id');
        $groups = [];
        $overLimitTenants = [];

        foreach ($pinned as $versionId => $rows) {
            $source = $sources[$versionId] ?? null;
            if (! $source) {
                continue;
            }
            $diff = $this->diffMaps($this->versionMap($source), $target);
            $tenantIds = $rows->pluck('tenant_id')->map(static fn ($id) => (int) $id)->all();

            $overLimit = [];
            foreach ($diff['limit_changes'] as $change) {
                if (! $change['decrease'] || $change['to'] === null || ! $this->usage->isMeasurable($change['code'])) {
                    continue;
                }
                $usage = $this->latestUsage($tenantIds, $change['code']);
                $over = array_keys(array_filter($usage, static fn (int $value) => $value > $change['to']));
                if ($over !== []) {
                    $overLimit[] = ['code' => $change['code'], 'name' => $change['name'], 'limit' => $change['to'], 'tenant_count' => count($over)];
                    foreach ($over as $tenantId) {
                        $overLimitTenants[$tenantId] = true;
                    }
                }
            }

            $groups[] = [
                'from_version_id' => (int) $versionId,
                'from_version_number' => $source->version_number,
                'from_status' => $source->status,
                'tenant_count' => count($tenantIds),
                'features_gained' => $diff['features_gained'],
                'features_lost' => $diff['features_lost'],
                'limit_changes' => $diff['limit_changes'],
                'over_limit' => $overLimit,
            ];
        }

        $affected = array_sum(array_column($groups, 'tenant_count'));
        $lossy = array_filter($groups, static fn (array $g) => $g['features_lost'] !== [] || $g['over_limit'] !== []);

        return [
            'version' => ['id' => $candidate->id, 'plan_id' => $candidate->plan_id, 'version_number' => $candidate->version_number, 'status' => $candidate->status],
            'tenants_on_candidate' => TenantSubscription::query()->where('plan_version_id', $candidate->id)->current()->count(),
            'tenants_on_other_versions' => $affected,
            'tenants_over_new_limits' => count($overLimitTenants),
            'groups' => $groups,
            'requires_confirmation' => $lossy !== [],
            'data_preserved' => true,
        ];
    }

    /**
     * @return array<string, array{enabled: bool, limit: int|null}>
     */
    private function versionMap(PlanVersion $version): array
    {
        $version->loadMissing('entitlements');
        $map = [];
        foreach ($version->entitlements as $e) {
            $code = $this->catalog->codeForId((int) $e->feature_id);
            if ($code) {
                $map[$code] = ['enabled' => (bool) $e->is_enabled, 'limit' => $e->numeric_value === null ? null : (int) $e->numeric_value];
            }
        }

        return $map;
    }

    /**
     * @param  array<string, array{enabled: bool, limit: int|null}>  $from
     * @param  array<string, array{enabled: bool, limit: int|null}>  $to
     * @return array{features_gained: list<array<string, mixed>>, features_lost: list<array<string, mixed>>, limit_changes: list<array<string, mixed>>}
     */
    private function diffMaps(array $from, array $to): array
    {
        $gained = [];
        $lost = [];
        $limits = [];
        foreach ($this->catalog->features() as $code => $feature) {
            if ($feature['is_core']) {
                continue;
            }
            $was = (bool) ($from[$code]['enabled'] ?? false);
            $will = (bool) ($to[$code]['enabled'] ?? false);

            if (! in_array($feature['type'], ['LIMIT', 'QUOTA', 'USAGE'], true)) {
                if ($was && ! $will) {
                    $lost[] = ['code' => $code, 'name' => $feature['name']];
                } elseif (! $was && $will) {
                    $gained[] = ['code' => $code, 'name' => $feature['name']];
                }

                continue;
            }

            $fromLimit = $was ? ($from[$code]['limit'] ?? null) : 0;
            $toLimit = $will ? ($to[$code]['limit'] ?? null) : 0;
            if ($fromLimit === $toLimit) {
                continue;
            }
            $limits[] = [
                'code' => $code,
                'name' => $feature['name'],
                'unit' => $feature['unit'],
                'from' => $fromLimit,
                'to' => $toLimit,
                'decrease' => $toLimit !== null && ($fromLimit === null || $toLimit < $fromLimit),
            ];
        }

        return ['features_gained' => $gained, 'features_lost' => $lost, 'limit_changes' => $limits];
    }

    /**
     * Latest daily snapshot per tenant; tenants without one are measured live (bounded).
     *
     * @param  list<int>  $tenantIds
     * @return array<int, int>
     */
    private function latestUsage(array $tenantIds, string $code): array
    {
        $featureId = (int) ($this->catalog->feature($code)['id'] ?? 0);
        $usage = [];
        if ($featureId > 0) {
            foreach (array_chunk($tenantIds, 500) as $chunk) {
                $rows = TenantUsageSnapshot::query()
                    ->where('feature_id', $featureId)
                    ->whereIn('tenant_id', $chunk)
                    ->orderBy('snapshot_date')
                    ->get(['tenant_id', 'usage_value']);
                foreach ($rows as $row) {
                    $usage[(int) $row->tenant_id] = (int) $row->usage_value;
                }
            }
        }

        $missing = array_slice(array_values(array_diff($tenantIds, array_keys($usage))), 0, self::LIVE_MEASUREMENT_CAP);
        foreach ($missing as $tenantId) {
            $usage[$tenantId] = (int) $this->usage->current($tenantId, $code);
        }

        return $usage;
    }
}
