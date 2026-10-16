<?php

namespace Modules\Subscriptions\Services;

use App\Support\MoneyMath;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Models\SubscriptionCatalogAudit;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Subscriptions\Support\EntitlementCacheVersion;
use Modules\Subscriptions\Support\SubscriptionCatalogDefinition;
use Modules\Subscriptions\Support\TaxCalculator;
use Modules\Tenants\Models\SubscriptionSettings;

/**
 * Platform subscription policies stored on the existing subscription_settings singleton.
 *
 * Only allowlisted keys and values are accepted; data-destructive or security-weakening
 * options do not exist (downgrades always preserve data, core features stay on).
 */
class SubscriptionPolicyService
{
    public const OVER_LIMIT_BLOCK_NEW = 'BLOCK_NEW';

    public const OVER_LIMIT_WARN_ONLY = 'WARN_ONLY';

    /** @var list<string> */
    public const EXEMPTABLE_FLOWS = ['sacrament_recipient', 'marriage_transition', 'family_split'];

    /** @var array<string, mixed>|null */
    private ?array $policiesMemo = null;

    public function __construct(private readonly SubscriptionAuditService $audit) {}

    /**
     * @return array<string, mixed>
     */
    public function policies(): array
    {
        if ($this->policiesMemo !== null) {
            return $this->policiesMemo;
        }

        $stored = SubscriptionSettings::current()->getAttribute('policies');
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        $this->policiesMemo = array_replace_recursive(
            SubscriptionCatalogDefinition::defaultPolicies(),
            is_array($stored) ? $stored : []
        );

        return $this->policiesMemo;
    }

    public function forgetPoliciesMemo(): void
    {
        $this->policiesMemo = null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->policies(), $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function platformTax(): array
    {
        return (array) $this->get('tax', []);
    }

    public function isFlowExempt(string $flow): bool
    {
        return in_array($flow, (array) $this->get('limit_exempt_flows', []), true);
    }

    public function blocksOverLimit(): bool
    {
        return $this->get('over_limit_behavior') !== self::OVER_LIMIT_WARN_ONLY;
    }

    public function defaultPlanId(): ?int
    {
        $id = SubscriptionSettings::current()->getAttribute('default_plan_id');

        return $id ? (int) $id : null;
    }

    public function defaultPlan(): ?Plan
    {
        $id = $this->defaultPlanId();

        return $id ? Plan::query()->assignable()->find($id) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $plan = $this->defaultPlanId() ? Plan::query()->find($this->defaultPlanId()) : null;

        return [
            'default_plan' => $plan ? ['id' => $plan->id, 'code' => $plan->code, 'name' => $plan->name] : null,
            'policies' => $this->policies(),
            'options' => [
                'over_limit_behavior' => [self::OVER_LIMIT_BLOCK_NEW, self::OVER_LIMIT_WARN_ONLY],
                'limit_exempt_flows' => self::EXEMPTABLE_FLOWS,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentTax(): array
    {
        $settings = SubscriptionSettings::current();
        $tax = $this->normalizeTaxPayload((array) $this->platformTax());
        $meta = $this->taxUpdateMeta((int) $settings->id);

        return [
            'tax' => $tax,
            'currency_code' => (string) $this->get('currency_code', 'INR'),
            'updated_at' => $settings->updated_at?->toIso8601String(),
            'updated_by' => $meta,
            'impact' => $this->taxImpact(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input  validated tax payload (tax group + optional reason)
     */
    public function updateTax(array $input, User $actor, ?string $reason = null): array
    {
        $beforeTax = $this->presentTax();
        $settings = SubscriptionSettings::current();

        DB::transaction(function () use ($settings, $input): void {
            $locked = SubscriptionSettings::query()->whereKey($settings->id)->lockForUpdate()->firstOrFail();
            $policies = $this->loadPoliciesFromRow($locked);
            $policies['tax'] = $this->normalizeTaxPayload(array_replace(
                (array) ($policies['tax'] ?? []),
                (array) $input['tax']
            ));
            $locked->forceFill(['policies' => $policies])->save();
            $this->policiesMemo = $policies;
        });

        $this->forgetPoliciesMemo();
        $afterTax = $this->presentTax();
        $this->audit->catalog(
            'policy',
            (int) $settings->id,
            'tax_policy_updated',
            ['tax' => $beforeTax['tax']],
            ['tax' => $afterTax['tax']],
            $actor,
            $reason
        );
        $this->forgetPublicPlansCache();

        return $afterTax;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    public function previewTax(array $input): ?array
    {
        $amount = MoneyMath::normalize((string) $input['amount']);
        $draft = isset($input['tax']) && is_array($input['tax']) ? $input['tax'] : null;
        $platform = $this->platformTax();
        $rate = MoneyMath::normalize((string) ($draft['rate_percent'] ?? $platform['rate_percent'] ?? '0'));
        $inclusive = array_key_exists('prices_include_tax', (array) $draft)
            ? (bool) $draft['prices_include_tax']
            : (bool) ($platform['prices_include_tax'] ?? false);

        $breakdown = TaxCalculator::breakdown($amount, $rate, $inclusive);
        if ($breakdown === null) {
            return null;
        }

        return $breakdown + [
            'currency_code' => (string) $this->get('currency_code', 'INR'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function taxImpact(): array
    {
        $catalogRows = PlanVersion::query()
            ->select(['plan_versions.id', 'plan_versions.tax_rate_percent', 'subscription_plans.name', 'subscription_plans.code'])
            ->join('subscription_plans', 'subscription_plans.id', '=', 'plan_versions.plan_id')
            ->where('plan_versions.status', PlanVersion::STATUS_ACTIVE)
            ->where('subscription_plans.is_legacy', false)
            ->where('subscription_plans.status', '!=', Plan::STATUS_ARCHIVED)
            ->where('subscription_plans.is_assignable', true)
            ->get();

        $inheritingPlans = [];
        $overriddenPlans = [];
        foreach ($catalogRows as $row) {
            $item = ['code' => (string) $row->code, 'name' => (string) $row->name];
            if ($row->tax_rate_percent === null) {
                $inheritingPlans[] = $item;
            } else {
                $overriddenPlans[] = $item;
            }
        }

        $subscriptionRows = TenantSubscription::query()
            ->select(['tenant_subscriptions.id', 'plan_versions.tax_rate_percent'])
            ->join('plan_versions', 'plan_versions.id', '=', 'tenant_subscriptions.plan_version_id')
            ->where('tenant_subscriptions.record_status', TenantSubscription::RECORD_CURRENT)
            ->get();

        $subsInheriting = 0;
        $subsOverridden = 0;
        foreach ($subscriptionRows as $row) {
            if ($row->tax_rate_percent === null) {
                $subsInheriting++;
            } else {
                $subsOverridden++;
            }
        }

        return [
            'catalog' => [
                'inheriting_count' => count($inheritingPlans),
                'overridden_count' => count($overriddenPlans),
                'inheriting_plans' => $inheritingPlans,
                'overridden_plans' => $overriddenPlans,
            ],
            'subscriptions' => [
                'inheriting_count' => $subsInheriting,
                'overridden_count' => $subsOverridden,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input  validated by UpdateSubscriptionPoliciesRequest
     */
    public function update(array $input, User $actor, ?string $reason = null): array
    {
        $before = $this->present();
        $settings = SubscriptionSettings::current();
        $taxTouched = isset($input['tax']) && is_array($input['tax']);

        DB::transaction(function () use ($settings, $input): void {
            $locked = SubscriptionSettings::query()->whereKey($settings->id)->lockForUpdate()->firstOrFail();
            $policies = $this->loadPoliciesFromRow($locked);
            foreach (['usage_thresholds', 'over_limit_behavior', 'limit_exempt_flows', 'currency_code'] as $key) {
                if (array_key_exists($key, $input)) {
                    $policies[$key] = $input[$key];
                }
            }
            foreach (['trial', 'tax', 'upgrade_requests'] as $group) {
                if (isset($input[$group]) && is_array($input[$group])) {
                    $policies[$group] = array_replace($policies[$group] ?? [], $input[$group]);
                }
            }
            if (isset($policies['tax']) && is_array($policies['tax'])) {
                $policies['tax'] = $this->normalizeTaxPayload($policies['tax']);
            }
            $policies['usage_thresholds'] = $this->normalizeThresholds((array) $policies['usage_thresholds']);
            $policies['limit_exempt_flows'] = array_values(array_intersect(self::EXEMPTABLE_FLOWS, (array) $policies['limit_exempt_flows']));
            $policies['downgrade_behavior'] = 'PRESERVE_DATA';

            $defaultPlanId = $locked->getAttribute('default_plan_id');
            if (array_key_exists('default_plan_id', $input)) {
                $defaultPlanId = $input['default_plan_id'] === null ? null : $this->assertDefaultPlanEligible((int) $input['default_plan_id'])->id;
            }

            $locked->forceFill([
                'policies' => $policies,
                'default_plan_id' => $defaultPlanId,
            ])->save();
            $this->policiesMemo = $policies;
        });

        $this->forgetPoliciesMemo();
        $after = $this->present();
        $this->audit->catalog('policy', (int) $settings->id, 'policies_updated', $before, $after, $actor, $reason);
        if ($taxTouched) {
            $this->forgetPublicPlansCache();
        }

        return $after;
    }

    public function forgetPublicPlansCache(): void
    {
        Cache::forget('subscriptions:public_plans:c_'.EntitlementCacheVersion::catalog());
    }

    public function assertDefaultPlanEligible(int $planId): Plan
    {
        $plan = Plan::query()->assignable()->find($planId);
        if (! $plan || $plan->is_legacy) {
            throw SubscriptionException::planNotAvailable('The default plan must be an active, assignable catalog plan.');
        }
        if ($plan->isCustomPriced()) {
            throw SubscriptionException::changeNotAllowed('A custom-priced plan cannot be the default plan for new tenants.');
        }
        $hasActive = PlanVersion::query()->where('plan_id', $plan->id)->where('status', PlanVersion::STATUS_ACTIVE)->exists();
        if (! $hasActive) {
            throw SubscriptionException::versionNotActive('The default plan must have an active version.');
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $tax
     * @return array<string, mixed>
     */
    private function normalizeTaxPayload(array $tax): array
    {
        $defaults = SubscriptionCatalogDefinition::defaultPolicies()['tax'];
        $merged = array_replace_recursive($defaults, $tax);
        $merged['label'] = trim((string) ($merged['label'] ?? 'Tax')) ?: 'Tax';
        $merged['rate_percent'] = MoneyMath::normalize((string) ($merged['rate_percent'] ?? '0'));
        $merged['prices_include_tax'] = (bool) ($merged['prices_include_tax'] ?? false);
        if (isset($merged['jurisdiction']) && is_array($merged['jurisdiction'])) {
            $country = strtoupper(trim((string) ($merged['jurisdiction']['country_code'] ?? '')));
            $merged['jurisdiction']['country_code'] = $country !== '' ? $country : ($defaults['jurisdiction']['country_code'] ?? 'IN');
            $system = trim((string) ($merged['jurisdiction']['tax_system'] ?? ''));
            $merged['jurisdiction']['tax_system'] = $system !== '' ? $system : ($defaults['jurisdiction']['tax_system'] ?? 'GST');
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPoliciesFromRow(SubscriptionSettings $settings): array
    {
        $stored = $settings->getAttribute('policies');
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        return array_replace_recursive(
            SubscriptionCatalogDefinition::defaultPolicies(),
            is_array($stored) ? $stored : []
        );
    }

    /**
     * @return array{actor_id: int|null, actor_role: string|null, updated_at: string|null}|null
     */
    private function taxUpdateMeta(int $settingsId): ?array
    {
        $audit = SubscriptionCatalogAudit::query()
            ->where('entity_type', 'policy')
            ->where('entity_id', $settingsId)
            ->whereIn('operation', ['tax_policy_updated', 'policies_updated'])
            ->orderByDesc('created_at')
            ->first();

        if (! $audit) {
            return null;
        }

        return [
            'actor_id' => $audit->actor_id,
            'actor_role' => $audit->actor_role,
            'updated_at' => $audit->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<mixed>  $thresholds
     * @return list<int>
     */
    private function normalizeThresholds(array $thresholds): array
    {
        $values = array_values(array_unique(array_filter(
            array_map('intval', $thresholds),
            static fn (int $v) => $v >= 1 && $v <= 100
        )));
        sort($values);

        return $values === [] ? [100] : $values;
    }
}
