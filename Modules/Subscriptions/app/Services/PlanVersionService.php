<?php

namespace Modules\Subscriptions\Services;

use App\Support\MoneyMath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanEntitlement;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Subscriptions\Services\Catalog\LegacyColumns;
use Modules\Subscriptions\Services\Catalog\PlanVersionContentHasher;

/**
 * Plan version lifecycle: DRAFT → (SCHEDULED) → ACTIVE → RETIRED.
 *
 * Published versions are immutable; tenants stay pinned to the version they were assigned
 * (grandfathering) until an administrator explicitly moves them.
 */
class PlanVersionService
{
    public function __construct(
        private readonly FeatureDependencyValidator $dependencies,
        private readonly PlanVersionContentHasher $hasher,
        private readonly SubscriptionAuditService $audit,
    ) {}

    /**
     * @param  bool  $copyAcrossPlans  only for plan duplication, where the new plan starts from another plan's terms
     */
    public function createDraft(Plan $plan, User $actor, ?PlanVersion $from = null, bool $copyAcrossPlans = false): PlanVersion
    {
        if ($plan->isArchived() || $plan->is_legacy) {
            throw SubscriptionException::changeNotAllowed('Archived or grandfathered plans cannot get new versions.');
        }
        if (PlanVersion::query()->where('plan_id', $plan->id)->where('status', PlanVersion::STATUS_DRAFT)->exists()) {
            throw SubscriptionException::invalidState('This plan already has a draft version. Edit or delete it first.');
        }

        $from ??= PlanVersion::query()->where('plan_id', $plan->id)
            ->whereIn('status', [PlanVersion::STATUS_ACTIVE, PlanVersion::STATUS_SCHEDULED])
            ->orderByDesc('version_number')
            ->first();
        if ($from && ! $copyAcrossPlans && (int) $from->plan_id !== (int) $plan->id) {
            throw SubscriptionException::changeNotAllowed('A draft can only be copied from a version of the same plan.');
        }

        return DB::transaction(function () use ($plan, $actor, $from): PlanVersion {
            Plan::withTrashed()->whereKey($plan->id)->lockForUpdate()->first(['id']);
            $number = (int) PlanVersion::query()->where('plan_id', $plan->id)->max('version_number') + 1;

            $draft = PlanVersion::query()->create([
                'plan_id' => $plan->id,
                'version_number' => $number,
                'status' => PlanVersion::STATUS_DRAFT,
                'currency_code' => $from?->currency_code ?? 'INR',
                'monthly_price' => $from?->monthly_price,
                'annual_price' => $from?->annual_price,
                'setup_fee' => $from?->setup_fee,
                'tax_inclusive' => (bool) ($from?->tax_inclusive ?? false),
                'tax_rate_percent' => $from?->tax_rate_percent,
                'tax_label' => $from?->tax_label,
                'trial_days' => $from?->trial_days,
                'billing_intervals' => $from?->billing_intervals ?? ($plan->isCustomPriced() ? [PlanVersion::INTERVAL_CUSTOM] : [PlanVersion::INTERVAL_MONTHLY, PlanVersion::INTERVAL_ANNUAL]),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            if ($from) {
                foreach ($from->entitlements()->get() as $entitlement) {
                    $draft->entitlements()->create($entitlement->only(['feature_id', 'is_enabled', 'numeric_value', 'tier_value', 'config']));
                }
            } else {
                foreach (Feature::query()->where('is_core', true)->get() as $core) {
                    $draft->entitlements()->create(['feature_id' => $core->id, 'is_enabled' => true]);
                }
            }

            $present = $draft->entitlements()->pluck('feature_id');
            foreach (Feature::query()->whereNotIn('id', $present)->get() as $feature) {
                if ($feature->isNumeric()) {
                    continue;
                }
                $draft->entitlements()->create([
                    'feature_id' => $feature->id,
                    'is_enabled' => (bool) $feature->is_core,
                ]);
            }

            $this->audit->catalog('plan_version', (int) $draft->id, 'plan_version_draft_created', null, $this->present($draft), $actor);

            return $draft;
        });
    }

    /**
     * @param  array<string, mixed>  $terms  validated by UpdatePlanVersionRequest
     */
    public function updateDraft(PlanVersion $version, array $terms, User $actor): PlanVersion
    {
        $this->assertDraft($version);
        $before = $this->present($version);

        $fill = [];
        foreach (['monthly_price', 'annual_price', 'setup_fee'] as $money) {
            if (array_key_exists($money, $terms)) {
                $fill[$money] = $terms[$money] === null || $terms[$money] === '' ? null : MoneyMath::normalize((string) $terms[$money]);
            }
        }
        if (array_key_exists('tax_rate_percent', $terms)) {
            $fill['tax_rate_percent'] = $terms['tax_rate_percent'] === null || $terms['tax_rate_percent'] === '' ? null : MoneyMath::normalize((string) $terms['tax_rate_percent']);
        }
        foreach (['currency_code', 'tax_inclusive', 'tax_label', 'trial_days', 'billing_intervals', 'change_notes'] as $field) {
            if (array_key_exists($field, $terms)) {
                $fill[$field] = $terms[$field];
            }
        }
        if (isset($fill['currency_code'])) {
            $fill['currency_code'] = strtoupper((string) $fill['currency_code']);
        }
        if (isset($fill['billing_intervals'])) {
            $fill['billing_intervals'] = array_values(array_unique(array_map('strtoupper', (array) $fill['billing_intervals'])));
        }
        $fill['updated_by'] = $actor->id;

        $version->forceFill($fill)->save();
        $this->audit->catalog('plan_version', (int) $version->id, 'plan_version_draft_updated', $before, $this->present($version->fresh()), $actor);

        return $version->fresh('entitlements');
    }

    /**
     * Replace the draft's entitlements.
     *
     * @param  list<array{feature_code: string, is_enabled?: bool, numeric_value?: int|null, tier_value?: string|null}>  $rows
     */
    public function setEntitlements(PlanVersion $version, array $rows, User $actor): PlanVersion
    {
        $this->assertDraft($version);
        $features = Feature::query()->get()->keyBy('code');
        $errors = [];
        $normalized = [];

        foreach ($rows as $i => $row) {
            $code = strtoupper((string) ($row['feature_code'] ?? ''));
            /** @var Feature|null $feature */
            $feature = $features[$code] ?? null;
            if (! $feature) {
                $errors["entitlements.{$i}.feature_code"][] = "Unknown feature {$code}.";

                continue;
            }
            $enabled = (bool) ($row['is_enabled'] ?? true);
            if ($feature->is_core) {
                $enabled = true;
            }
            $numeric = $row['numeric_value'] ?? null;
            if ($numeric !== null && ! $feature->isNumeric()) {
                $errors["entitlements.{$i}.numeric_value"][] = "{$code} does not take a numeric limit.";
            }
            if ($numeric !== null && (int) $numeric < 0) {
                $errors["entitlements.{$i}.numeric_value"][] = 'Limits must be zero or more.';
            }
            $tier = $row['tier_value'] ?? null;
            if ($tier !== null) {
                $options = (array) ($feature->tier_options ?? []);
                if ($feature->feature_type !== Feature::TYPE_TIER || ! in_array($tier, $options, true)) {
                    $errors["entitlements.{$i}.tier_value"][] = "Invalid tier for {$code}.";
                }
            }
            $normalized[$code] = [
                'feature_id' => $feature->id,
                'is_enabled' => $enabled,
                'numeric_value' => $numeric === null ? null : (int) $numeric,
                'tier_value' => $tier,
            ];
        }

        foreach ($features->where('is_core', true) as $code => $core) {
            $normalized[$code] ??= ['feature_id' => $core->id, 'is_enabled' => true, 'numeric_value' => null, 'tier_value' => null];
        }

        $enabledBoolean = array_keys(array_filter($normalized, static fn (array $r) => $r['is_enabled']));
        foreach ($this->dependencies->missingDependencies($enabledBoolean) as $code => $missing) {
            $errors["dependencies.{$code}"][] = "{$code} requires ".implode(', ', $missing).'.';
        }

        if ($errors !== []) {
            throw SubscriptionException::catalogInvalid('Some entitlements are invalid.', $errors);
        }

        $before = $this->present($version);
        DB::transaction(function () use ($version, $normalized, $actor): void {
            PlanEntitlement::query()->where('plan_version_id', $version->id)->get()->each->delete();
            foreach ($normalized as $row) {
                $version->entitlements()->create($row);
            }
            $version->forceFill(['updated_by' => $actor->id])->save();
        });

        $fresh = $version->fresh('entitlements');
        $this->audit->catalog('plan_version', (int) $version->id, 'plan_version_entitlements_updated', $before, $this->present($fresh), $actor);

        return $fresh;
    }

    /**
     * Publish now (ACTIVE) or at a future time (SCHEDULED).
     */
    public function publish(PlanVersion $version, User $actor, ?Carbon $effectiveFrom = null, ?string $reason = null): PlanVersion
    {
        $this->assertDraft($version);
        $plan = $version->plan;
        $this->assertPublishable($plan, $version);

        if ($effectiveFrom && $effectiveFrom->isFuture()) {
            if (PlanVersion::query()->where('plan_id', $plan->id)->where('status', PlanVersion::STATUS_SCHEDULED)->exists()) {
                throw SubscriptionException::invalidState('Another version of this plan is already scheduled.');
            }
            $before = $this->present($version);
            $version->forceFill([
                'status' => PlanVersion::STATUS_SCHEDULED,
                'effective_from' => $effectiveFrom,
                'content_hash' => $this->hasher->hash($version),
                'published_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();
            $this->audit->catalog('plan_version', (int) $version->id, 'plan_version_scheduled', $before, $this->present($version->fresh()), $actor, $reason);

            return $version->fresh('entitlements');
        }

        return $this->activate($version, $actor, $reason);
    }

    public function unschedule(PlanVersion $version, User $actor, ?string $reason = null): PlanVersion
    {
        if ($version->status !== PlanVersion::STATUS_SCHEDULED) {
            throw SubscriptionException::invalidState('Only scheduled versions can be unscheduled.');
        }
        $before = $this->present($version);
        $version->forceFill(['status' => PlanVersion::STATUS_DRAFT, 'effective_from' => null, 'published_by' => null, 'updated_by' => $actor->id])->save();
        $this->audit->catalog('plan_version', (int) $version->id, 'plan_version_unscheduled', $before, $this->present($version->fresh()), $actor, $reason);

        return $version->fresh('entitlements');
    }

    public function retire(PlanVersion $version, User $actor, ?string $reason = null): PlanVersion
    {
        if ($version->status !== PlanVersion::STATUS_ACTIVE) {
            throw SubscriptionException::invalidState('Only the active version can be retired.');
        }
        $before = $this->present($version);
        $version->forceFill([
            'status' => PlanVersion::STATUS_RETIRED,
            'retired_at' => now(),
            'retired_by' => $actor->id,
            'updated_by' => $actor->id,
        ])->save();
        $this->syncPlanMirror($version->plan);
        $this->audit->catalog('plan_version', (int) $version->id, 'plan_version_retired', $before, $this->present($version->fresh()), $actor, $reason);

        return $version->fresh('entitlements');
    }

    public function deleteDraft(PlanVersion $version, User $actor): void
    {
        $this->assertDraft($version);
        if (TenantSubscription::query()->where('plan_version_id', $version->id)->exists()) {
            throw SubscriptionException::invalidState('This version is referenced by tenant subscriptions.');
        }
        $before = $this->present($version);
        DB::transaction(function () use ($version): void {
            PlanEntitlement::query()->where('plan_version_id', $version->id)->get()->each->delete();
            $version->delete();
        });
        $this->audit->catalog('plan_version', (int) $version->id, 'plan_version_draft_deleted', $before, null, $actor);
    }

    /**
     * Scheduler: activate SCHEDULED versions whose effective_from has passed.
     */
    public function activateDueScheduled(): int
    {
        $count = 0;
        PlanVersion::query()
            ->where('status', PlanVersion::STATUS_SCHEDULED)
            ->where('effective_from', '<=', now())
            ->orderBy('effective_from')
            ->each(function (PlanVersion $version) use (&$count): void {
                $this->activate($version, null, 'Scheduled activation');
                $count++;
            });

        return $count;
    }

    private function activate(PlanVersion $version, ?User $actor, ?string $reason): PlanVersion
    {
        return DB::transaction(function () use ($version, $actor, $reason): PlanVersion {
            $plan = Plan::withTrashed()->whereKey($version->plan_id)->lockForUpdate()->firstOrFail();
            $before = $this->present($version);

            PlanVersion::query()->where('plan_id', $plan->id)->where('status', PlanVersion::STATUS_ACTIVE)->get()
                ->each(function (PlanVersion $active) use ($actor): void {
                    $active->forceFill(['status' => PlanVersion::STATUS_RETIRED, 'retired_at' => now(), 'retired_by' => $actor?->id])->save();
                });

            $version->forceFill([
                'status' => PlanVersion::STATUS_ACTIVE,
                'effective_from' => $version->effective_from && $version->effective_from->isPast() ? $version->effective_from : now(),
                'published_at' => now(),
                'published_by' => $actor?->id ?? $version->published_by,
                'content_hash' => $this->hasher->hash($version),
                'updated_by' => $actor?->id,
            ])->save();

            if ($plan->status === Plan::STATUS_DRAFT) {
                $plan->forceFill(['status' => Plan::STATUS_ACTIVE])->save();
            }
            $this->syncPlanMirror($plan->fresh());

            $this->audit->catalog('plan_version', (int) $version->id, 'plan_version_published', $before, $this->present($version->fresh()), $actor, $reason);

            return $version->fresh('entitlements');
        });
    }

    public function assertPublishable(Plan $plan, PlanVersion $version): void
    {
        $errors = [];
        if ($plan->isArchived() || $plan->trashed()) {
            $errors['plan'][] = 'Archived plans cannot be published.';
        }

        $intervals = (array) $version->billing_intervals;
        if ($intervals === []) {
            $errors['billing_intervals'][] = 'Choose at least one billing interval.';
        }
        if ($plan->pricing_type === Plan::PRICING_FIXED) {
            if (in_array(PlanVersion::INTERVAL_MONTHLY, $intervals, true) && $version->monthly_price === null) {
                $errors['monthly_price'][] = 'A monthly price is required for monthly billing.';
            }
            if (in_array(PlanVersion::INTERVAL_ANNUAL, $intervals, true) && $version->annual_price === null) {
                $errors['annual_price'][] = 'An annual price is required for annual billing.';
            }
        }
        if ($plan->pricing_type === Plan::PRICING_FREE
            && (($version->monthly_price !== null && MoneyMath::isPositive((string) $version->monthly_price))
                || ($version->annual_price !== null && MoneyMath::isPositive((string) $version->annual_price)))) {
            $errors['monthly_price'][] = 'Free plans cannot have a price.';
        }

        $version->loadMissing('entitlements.feature');
        $enabled = $version->entitlements->where('is_enabled', true)->map(fn ($e) => $e->feature?->code)->filter()->values()->all();
        foreach ($this->dependencies->missingDependencies($enabled) as $code => $missing) {
            $errors["dependencies.{$code}"][] = "{$code} requires ".implode(', ', $missing).'.';
        }

        if ($errors !== []) {
            throw SubscriptionException::catalogInvalid('This version cannot be published yet.', $errors);
        }
    }

    /**
     * Legacy columns on subscription_plans mirror the ACTIVE version (rollback safety).
     */
    public function syncPlanMirror(Plan $plan): void
    {
        $active = PlanVersion::query()->where('plan_id', $plan->id)->where('status', PlanVersion::STATUS_ACTIVE)->with('entitlements.feature')->first();
        if (! $active) {
            $plan->forceFill(['active' => false])->save();

            return;
        }

        $byCode = $active->entitlements->keyBy(fn ($e) => $e->feature?->code);
        $limit = static function (string $code) use ($byCode): ?int {
            $e = $byCode[$code] ?? null;

            return $e && $e->is_enabled ? ($e->numeric_value === null ? null : (int) $e->numeric_value) : null;
        };

        $plan->forceFill([
            'price' => $active->monthly_price ?? '0.00',
            'features' => $active->entitlements->filter(fn ($e) => $e->is_enabled && $e->feature?->legacy_key)->map(fn ($e) => $e->feature->legacy_key)->values()->all(),
            'max_users' => LegacyColumns::fromLimit($limit('STAFF_USER_LIMIT')),
            'max_storage_mb' => LegacyColumns::fromLimit($limit('STORAGE_MB')),
            'active' => $plan->status === Plan::STATUS_ACTIVE && $plan->is_assignable && ! $plan->is_legacy,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(PlanVersion $version): array
    {
        $version->loadMissing('entitlements.feature');

        return [
            'id' => $version->id,
            'plan_id' => $version->plan_id,
            'version_number' => $version->version_number,
            'status' => $version->status,
            'currency_code' => $version->currency_code,
            'monthly_price' => $version->monthly_price,
            'annual_price' => $version->annual_price,
            'setup_fee' => $version->setup_fee,
            'tax_inclusive' => (bool) $version->tax_inclusive,
            'tax_rate_percent' => $version->tax_rate_percent,
            'tax_label' => $version->tax_label,
            'trial_days' => $version->trial_days,
            'billing_intervals' => $version->billing_intervals,
            'effective_from' => $version->effective_from?->toIso8601String(),
            'content_hash' => $version->content_hash,
            'entitlements' => $version->entitlements->map(fn ($e) => [
                'feature_code' => $e->feature?->code,
                'is_enabled' => (bool) $e->is_enabled,
                'numeric_value' => $e->numeric_value,
                'tier_value' => $e->tier_value,
            ])->values()->all(),
        ];
    }

    private function assertDraft(PlanVersion $version): void
    {
        if (! $version->isEditable()) {
            throw SubscriptionException::invalidState('Published versions cannot be changed. Create a new draft version instead.');
        }
    }
}
