<?php

namespace Modules\Subscriptions\Services;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Models\SubscriptionUpgradeRequest;
use Modules\Subscriptions\Models\TenantSubscription;

/**
 * Plan identity and presentation. Commercial terms live on versions (PlanVersionService).
 */
class PlanService
{
    /** @var list<string> */
    public const SAFE_HEADER_FIELDS = [
        'name', 'description', 'short_description', 'badge_label', 'is_featured',
    ];

    /** @var list<string> */
    public const RESTRICTED_HEADER_FIELDS = [
        'is_public', 'is_assignable', 'display_order',
    ];

    public function __construct(
        private readonly PlanVersionService $versions,
        private readonly SubscriptionPolicyService $policies,
        private readonly SubscriptionAuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated by StorePlanRequest
     */
    public function create(array $data, User $actor, ?PlanVersion $copyFrom = null): Plan
    {
        $code = strtoupper((string) $data['code']);
        if (Plan::withTrashed()->where('code', $code)->exists()) {
            throw SubscriptionException::catalogInvalid('A plan with this code already exists.', ['code' => ['Plan code must be unique.']]);
        }

        return DB::transaction(function () use ($data, $code, $actor, $copyFrom): Plan {
            $plan = Plan::query()->create([
                'code' => $code,
                'key' => $this->uniqueKey(strtolower($code)),
                'slug' => $this->uniqueSlug((string) ($data['slug'] ?? $data['name'])),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'short_description' => $data['short_description'] ?? null,
                'pricing_type' => $data['pricing_type'] ?? Plan::PRICING_FIXED,
                'status' => Plan::STATUS_DRAFT,
                'is_public' => (bool) ($data['is_public'] ?? false),
                'is_featured' => (bool) ($data['is_featured'] ?? false),
                'is_assignable' => (bool) ($data['is_assignable'] ?? true),
                'is_legacy' => false,
                'badge_label' => $data['badge_label'] ?? null,
                'display_order' => (int) ($data['display_order'] ?? ((int) Plan::withTrashed()->max('display_order') + 10)),
                'price' => 0,
                'active' => false,
                'is_default' => false,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->versions->createDraft($plan, $actor, $copyFrom, $copyFrom !== null);
            $this->audit->catalog('plan', (int) $plan->id, 'plan_created', null, $this->present($plan) + [
                'copied_from_version_id' => $copyFrom?->id,
            ], $actor);

            return $plan->fresh();
        });
    }

    /**
     * New DRAFT plan whose first draft version copies the source plan's current terms and
     * entitlements. The source plan and its tenants are untouched.
     *
     * @param  array{code: string, name: string, slug?: string|null}  $data
     */
    public function duplicate(Plan $source, array $data, User $actor): Plan
    {
        $version = PlanVersion::query()->where('plan_id', $source->id)
            ->orderByRaw("CASE status WHEN 'ACTIVE' THEN 0 WHEN 'SCHEDULED' THEN 1 WHEN 'DRAFT' THEN 2 ELSE 3 END")
            ->orderByDesc('version_number')
            ->first();

        return $this->create([
            'code' => $data['code'],
            'name' => $data['name'],
            'slug' => $data['slug'] ?? null,
            'description' => $source->description,
            'short_description' => $source->short_description,
            'pricing_type' => $source->pricing_type,
            'is_public' => false,
            'is_featured' => false,
            'is_assignable' => true,
        ], $actor, $version);
    }

    /**
     * Churches currently on this plan, or with a scheduled (PENDING) assignment to it.
     * Superseded and cancelled rows do not count.
     */
    public function occupancyCount(Plan $plan): int
    {
        return (int) TenantSubscription::query()
            ->where('plan_id', $plan->id)
            ->whereIn('record_status', [
                TenantSubscription::RECORD_CURRENT,
                TenantSubscription::RECORD_PENDING,
            ])
            ->count();
    }

    /**
     * Whether plan header fields may be edited in place (legacy plans are read-only).
     */
    public function isEditable(Plan $plan, ?int $occupancy = null): bool
    {
        return ! $plan->is_legacy;
    }

    /**
     * @return array{code: string, message: string, tenant_count: int}|null
     */
    public function openUpgradeRequestCount(Plan $plan): int
    {
        return (int) SubscriptionUpgradeRequest::query()
            ->where('requested_plan_id', $plan->id)
            ->whereIn('status', SubscriptionUpgradeRequest::OPEN_STATUSES)
            ->count();
    }

    public function isDeletable(Plan $plan, ?int $occupancy = null): bool
    {
        return $this->deleteRestriction($plan, $occupancy) === null;
    }

    /**
     * @return array{code: string, message: string, tenant_count: int}|null
     */
    public function deleteRestriction(Plan $plan, ?int $occupancy = null): ?array
    {
        if ($plan->is_legacy) {
            return [
                'code' => SubscriptionException::PLAN_CHANGE_NOT_ALLOWED,
                'message' => 'Grandfathered legacy plans cannot be deleted.',
                'tenant_count' => $occupancy ?? $this->occupancyCount($plan),
            ];
        }

        if ($this->policies->defaultPlanId() === (int) $plan->id) {
            return [
                'code' => SubscriptionException::PLAN_CHANGE_NOT_ALLOWED,
                'message' => 'The default plan for new tenants cannot be deleted. Choose another default plan first.',
                'tenant_count' => $occupancy ?? $this->occupancyCount($plan),
            ];
        }

        $count = $occupancy ?? $this->occupancyCount($plan);
        if ($count > 0 || $this->openUpgradeRequestCount($plan) > 0) {
            $exception = SubscriptionException::planCannotDeleteBecauseAssigned($count);

            return [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'tenant_count' => $count,
            ];
        }

        return null;
    }

    public function editRestriction(Plan $plan, ?int $occupancy = null): ?array
    {
        if ($plan->is_legacy) {
            return [
                'code' => SubscriptionException::PLAN_CHANGE_NOT_ALLOWED,
                'message' => 'Grandfathered legacy plans cannot be edited.',
                'tenant_count' => $occupancy ?? $this->occupancyCount($plan),
            ];
        }

        return null;
    }

    /**
     * @return array{
     *   tenant_count: int,
     *   safe_fields: list<string>,
     *   restricted_fields: list<array{field: string, reason: string}>,
     *   immutable_fields: list<array{field: string, reason: string}>
     * }
     */
    public function editPolicy(Plan $plan, ?int $occupancy = null): array
    {
        $count = $occupancy ?? $this->occupancyCount($plan);
        $hasPublishedVersion = PlanVersion::query()
            ->where('plan_id', $plan->id)
            ->where('status', '!=', PlanVersion::STATUS_DRAFT)
            ->exists();

        $immutable = [
            ['field' => 'code', 'reason' => 'Plan code is permanent catalog identity.'],
            ['field' => 'key', 'reason' => 'Internal plan key is tied to church subscription records.'],
            ['field' => 'slug', 'reason' => 'URL slug cannot change after creation.'],
            ['field' => 'status', 'reason' => 'Use archive or restore instead of editing status directly.'],
        ];
        if ($hasPublishedVersion || $count > 0) {
            $immutable[] = [
                'field' => 'pricing_type',
                'reason' => $count > 0
                    ? 'Pricing type cannot change while churches are on this plan.'
                    : 'Pricing type cannot change after a version has been published.',
            ];
        }

        return [
            'tenant_count' => $count,
            'safe_fields' => self::SAFE_HEADER_FIELDS,
            'restricted_fields' => $this->restrictedFieldDefinitions(),
            'immutable_fields' => $immutable,
        ];
    }

    /**
     * @return list<array{field: string, reason: string}>
     */
    public function restrictedFieldDefinitions(): array
    {
        return [
            ['field' => 'is_public', 'reason' => 'Hiding a public plan removes it from the public catalog; churches already on the plan are unchanged.'],
            ['field' => 'is_assignable', 'reason' => 'Stopping assignment prevents new churches from choosing this plan; current churches stay on their version.'],
            ['field' => 'display_order', 'reason' => 'Display order affects upgrade and downgrade comparisons for future plan changes.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{field: string, reason: string}>
     */
    public function restrictedChanges(Plan $plan, array $data): array
    {
        $reasons = collect($this->restrictedFieldDefinitions())->keyBy('field');
        $out = [];

        if (array_key_exists('is_public', $data) && (bool) $data['is_public'] !== (bool) $plan->is_public && ! (bool) $data['is_public']) {
            $out[] = $reasons['is_public'];
        }
        if (array_key_exists('is_assignable', $data) && (bool) $data['is_assignable'] !== (bool) $plan->is_assignable && ! (bool) $data['is_assignable']) {
            $out[] = $reasons['is_assignable'];
        }
        if (array_key_exists('display_order', $data) && (int) $data['display_order'] !== (int) $plan->display_order) {
            $out[] = $reasons['display_order'];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data  validated by UpdatePlanRequest
     */
    public function update(Plan $plan, array $data, User $actor): Plan
    {
        if ($plan->is_legacy) {
            throw SubscriptionException::changeNotAllowed('Grandfathered legacy plans cannot be edited.');
        }

        $confirmImpact = (bool) ($data['confirm_assignment_impact'] ?? false);
        unset($data['confirm_assignment_impact']);

        return DB::transaction(function () use ($plan, $data, $actor, $confirmImpact): Plan {
            /** @var Plan $locked */
            $locked = Plan::withTrashed()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if ($locked->is_legacy) {
                throw SubscriptionException::changeNotAllowed('Grandfathered legacy plans cannot be edited.');
            }

            $occupancy = $this->occupancyCount($locked);
            $restricted = $this->restrictedChanges($locked, $data);
            if ($restricted !== [] && $occupancy > 0 && ! $confirmImpact) {
                throw SubscriptionException::planEditRequiresConfirmation($occupancy, $restricted);
            }

            $before = $this->present($locked);

            $fill = array_intersect_key($data, array_flip(array_merge(
                self::SAFE_HEADER_FIELDS,
                self::RESTRICTED_HEADER_FIELDS,
            )));
            if (array_key_exists('pricing_type', $data) && $data['pricing_type'] !== $locked->pricing_type) {
                if ($occupancy > 0) {
                    throw SubscriptionException::changeNotAllowed('Pricing type cannot change while churches are on this plan.');
                }
                $published = PlanVersion::query()->where('plan_id', $locked->id)->where('status', '!=', PlanVersion::STATUS_DRAFT)->exists();
                if ($published) {
                    throw SubscriptionException::changeNotAllowed('Pricing type cannot change after a version has been published.');
                }
                $fill['pricing_type'] = $data['pricing_type'];
            }
            if (array_key_exists('is_assignable', $fill) && ! $fill['is_assignable'] && $this->policies->defaultPlanId() === (int) $locked->id) {
                throw SubscriptionException::changeNotAllowed('The default plan for new tenants must stay assignable. Choose another default plan first.');
            }
            $fill['updated_by'] = $actor->id;

            $locked->forceFill($fill)->save();
            $this->versions->syncPlanMirror($locked->fresh());
            $this->audit->catalog('plan', (int) $locked->id, 'plan_updated', $before, $this->present($locked->fresh()), $actor);

            return $locked->fresh();
        });
    }

    public function archive(Plan $plan, User $actor, ?string $reason = null, bool $confirmAssignedChurches = false): Plan
    {
        if ($plan->isArchived()) {
            return $plan;
        }
        if ($this->policies->defaultPlanId() === (int) $plan->id) {
            throw SubscriptionException::changeNotAllowed('The default plan for new tenants cannot be archived. Choose another default plan first.');
        }

        return DB::transaction(function () use ($plan, $actor, $reason, $confirmAssignedChurches): Plan {
            /** @var Plan $locked */
            $locked = Plan::withTrashed()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if ($locked->isArchived()) {
                return $locked;
            }

            $occupancy = $this->occupancyCount($locked);
            if ($occupancy > 0 && ! $confirmAssignedChurches) {
                throw SubscriptionException::planArchiveRequiresConfirmation($occupancy);
            }

            $before = $this->present($locked);
            $locked->forceFill([
                'status' => Plan::STATUS_ARCHIVED,
                'archived_at' => now(),
                'active' => false,
                'updated_by' => $actor->id,
            ])->save();
            $this->audit->catalog('plan', (int) $locked->id, 'plan_archived', $before, $this->present($locked->fresh()), $actor, $reason);

            return $locked->fresh();
        });
    }

    public function delete(Plan $plan, User $actor, ?string $reason = null): void
    {
        if (! $actor->isSuperAdmin()) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'code' => 'FORBIDDEN',
                'message' => 'Only Ekklesia Super Admin can delete subscription plans.',
            ], 403));
        }

        DB::transaction(function () use ($plan, $actor, $reason): void {
            /** @var Plan $locked */
            $locked = Plan::withTrashed()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if ($locked->trashed()) {
                throw SubscriptionException::invalidState('This plan has already been deleted.');
            }

            $occupancy = $this->occupancyCount($locked);
            $restriction = $this->deleteRestriction($locked, $occupancy);
            if ($restriction !== null) {
                if ($restriction['code'] === SubscriptionException::PLAN_HAS_ASSIGNED_CHURCHES) {
                    throw SubscriptionException::planCannotDeleteBecauseAssigned($restriction['tenant_count']);
                }
                throw SubscriptionException::changeNotAllowed($restriction['message']);
            }

            $before = $this->present($locked);
            $locked->forceFill(['updated_by' => $actor->id])->save();
            $locked->delete();
            $this->audit->catalog('plan', (int) $locked->id, 'plan_deleted', $before, null, $actor, $reason);
        });
    }

    public function restore(Plan $plan, User $actor, ?string $reason = null): Plan
    {
        if (! $plan->isArchived()) {
            return $plan;
        }
        $hasActive = PlanVersion::query()->where('plan_id', $plan->id)->where('status', PlanVersion::STATUS_ACTIVE)->exists();
        $before = $this->present($plan);
        $plan->forceFill([
            'status' => $hasActive ? Plan::STATUS_ACTIVE : Plan::STATUS_DRAFT,
            'archived_at' => null,
            'updated_by' => $actor->id,
        ])->save();
        $this->versions->syncPlanMirror($plan->fresh());
        $this->audit->catalog('plan', (int) $plan->id, 'plan_restored', $before, $this->present($plan->fresh()), $actor, $reason);

        return $plan->fresh();
    }

    /**
     * @return array<string, int>
     */
    public function tenantCounts(Plan $plan): array
    {
        $rows = TenantSubscription::query()
            ->where('plan_id', $plan->id)
            ->current()
            ->selectRaw('plan_version_id, count(*) as aggregate')
            ->groupBy('plan_version_id')
            ->pluck('aggregate', 'plan_version_id');

        return $rows->mapWithKeys(fn ($count, $versionId) => [(string) $versionId => (int) $count])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Plan $plan): array
    {
        return $plan->only([
            'id', 'code', 'key', 'slug', 'name', 'short_description', 'pricing_type', 'status',
            'is_public', 'is_featured', 'is_assignable', 'is_legacy', 'badge_label', 'display_order',
        ]);
    }

    private function uniqueKey(string $base): string
    {
        $base = Str::limit(preg_replace('/[^a-z0-9_]+/', '_', $base) ?: 'plan', 40, '');
        $key = $base;
        $i = 2;
        while (Plan::withTrashed()->where('key', $key)->exists()) {
            $key = $base.'_'.$i++;
        }

        return $key;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'plan';
        $slug = $base;
        $i = 2;
        while (Plan::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
