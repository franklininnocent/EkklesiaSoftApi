<?php

namespace Modules\Subscriptions\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Models\TenantEntitlementOverride;
use Modules\Subscriptions\Services\Entitlements\EntitlementResolver;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantCacheVersion;

/**
 * Tenant-specific entitlement exceptions granted by Ekklesia administrators.
 * One open override per tenant + feature; a new grant replaces (revokes) the previous one.
 */
class EntitlementOverrideService
{
    public function __construct(
        private readonly EntitlementResolver $resolver,
        private readonly SubscriptionAuditService $audit,
    ) {}

    /**
     * @param  array{feature_code: string, mode: string, numeric_value?: int|null, tier_value?: string|null, reason: string, effective_from?: string|null, effective_until?: string|null}  $data
     */
    public function grant(Tenant $tenant, array $data, User $actor): TenantEntitlementOverride
    {
        $feature = Feature::query()->where('code', strtoupper($data['feature_code']))->first();
        if (! $feature) {
            throw SubscriptionException::catalogInvalid('Unknown feature.', ['feature_code' => ['Unknown feature.']]);
        }

        $mode = strtoupper($data['mode']);
        $this->assertModeAllowed($feature, $mode, $data);

        $from = ! empty($data['effective_from']) ? Carbon::parse($data['effective_from']) : null;
        $until = ! empty($data['effective_until']) ? Carbon::parse($data['effective_until']) : null;
        if ($until && $until->lessThanOrEqualTo($from ?? now())) {
            throw SubscriptionException::catalogInvalid('The end date must be after the start date.', ['effective_until' => ['Must be after the start.']]);
        }

        $override = DB::transaction(function () use ($tenant, $feature, $mode, $data, $from, $until, $actor): TenantEntitlementOverride {
            $previous = TenantEntitlementOverride::query()
                ->where('tenant_id', $tenant->id)
                ->where('feature_id', $feature->id)
                ->open()
                ->lockForUpdate()
                ->first();
            if ($previous) {
                $previous->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->id, 'revoke_reason' => 'Replaced by a new override'])->save();
            }

            $override = TenantEntitlementOverride::query()->create([
                'tenant_id' => $tenant->id,
                'feature_id' => $feature->id,
                'mode' => $mode,
                'numeric_value' => $mode === TenantEntitlementOverride::MODE_SET_LIMIT ? (int) $data['numeric_value'] : null,
                'tier_value' => $mode === TenantEntitlementOverride::MODE_SET_TIER ? $data['tier_value'] : null,
                'reason' => $data['reason'],
                'effective_from' => $from,
                'effective_until' => $until,
                'created_by' => $actor->id,
            ]);

            $this->audit->tenant((int) $tenant->id, 'entitlement_override_granted', $previous ? $this->present($previous) : [], $this->present($override), 'admin_ui', $data['reason'], $actor->id, SubscriptionAuditService::actorRole($actor));

            return $override;
        });

        $this->resolver->forget((int) $tenant->id);
        TenantCacheVersion::bump((int) $tenant->id);

        return $override->load('feature');
    }

    public function revoke(Tenant $tenant, TenantEntitlementOverride $override, User $actor, string $reason): TenantEntitlementOverride
    {
        if ((int) $override->tenant_id !== (int) $tenant->id) {
            abort(404);
        }
        if ($override->revoked_at) {
            return $override;
        }

        $before = $this->present($override);
        $override->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->id, 'revoke_reason' => $reason])->save();
        $this->audit->tenant((int) $tenant->id, 'entitlement_override_revoked', $before, $this->present($override), 'admin_ui', $reason, $actor->id, SubscriptionAuditService::actorRole($actor));

        $this->resolver->forget((int) $tenant->id);
        TenantCacheVersion::bump((int) $tenant->id);

        return $override->load('feature');
    }

    /**
     * @return array<string, mixed>
     */
    public function present(TenantEntitlementOverride $override): array
    {
        $override->loadMissing('feature');

        return [
            'id' => $override->id,
            'feature_code' => $override->feature?->code,
            'feature_name' => $override->feature?->name,
            'mode' => $override->mode,
            'numeric_value' => $override->numeric_value,
            'tier_value' => $override->tier_value,
            'reason' => $override->reason,
            'effective_from' => $override->effective_from?->toIso8601String(),
            'effective_until' => $override->effective_until?->toIso8601String(),
            'revoked_at' => $override->revoked_at?->toIso8601String(),
            'revoke_reason' => $override->revoke_reason,
            'created_by' => $override->created_by,
            'created_at' => $override->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertModeAllowed(Feature $feature, string $mode, array $data): void
    {
        if (! in_array($mode, TenantEntitlementOverride::MODES, true)) {
            throw SubscriptionException::catalogInvalid('Unknown override mode.', ['mode' => ['Unknown mode.']]);
        }
        if ($feature->is_core && $mode === TenantEntitlementOverride::MODE_DISABLE) {
            throw SubscriptionException::changeNotAllowed('Core features cannot be disabled.');
        }
        $numericModes = [TenantEntitlementOverride::MODE_SET_LIMIT, TenantEntitlementOverride::MODE_UNLIMITED];
        if (in_array($mode, $numericModes, true) && ! $feature->isNumeric()) {
            throw SubscriptionException::catalogInvalid('Limits can only be set on limit features.', ['mode' => ['Not a limit feature.']]);
        }
        if ($mode === TenantEntitlementOverride::MODE_SET_LIMIT && (! isset($data['numeric_value']) || (int) $data['numeric_value'] < 0)) {
            throw SubscriptionException::catalogInvalid('Enter a limit of zero or more.', ['numeric_value' => ['Required.']]);
        }
        if ($mode === TenantEntitlementOverride::MODE_SET_TIER
            && ($feature->feature_type !== Feature::TYPE_TIER || ! in_array($data['tier_value'] ?? null, (array) $feature->tier_options, true))) {
            throw SubscriptionException::catalogInvalid('Choose a valid tier.', ['tier_value' => ['Invalid tier.']]);
        }
    }
}
