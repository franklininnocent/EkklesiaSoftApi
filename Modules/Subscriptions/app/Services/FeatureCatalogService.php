<?php

namespace Modules\Subscriptions\Services;

use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Models\PlanEntitlement;
use Modules\Subscriptions\Services\Entitlements\EntitlementCatalog;

/**
 * Feature catalog administration. Core flags and legacy keys are system-owned and cannot
 * be changed through the API (core features are the safety floor no plan can remove).
 */
class FeatureCatalogService
{
    public function __construct(
        private readonly EntitlementCatalog $catalog,
        private readonly FeatureDependencyValidator $validator,
        private readonly SubscriptionAuditService $audit,
        private readonly Catalog\CatalogBootstrapper $bootstrapper,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated by StoreFeatureRequest
     */
    public function create(array $data, User $actor): Feature
    {
        $code = strtoupper((string) $data['code']);
        if (Feature::query()->where('code', $code)->exists()) {
            throw SubscriptionException::catalogInvalid('A feature with this code already exists.', ['code' => ['Feature code must be unique.']]);
        }

        $feature = Feature::query()->create([
            'code' => $code,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? 'general',
            'module_key' => $data['module_key'] ?? null,
            'feature_type' => $data['feature_type'],
            'unit' => $data['unit'] ?? null,
            'is_core' => false,
            'legacy_default' => false,
            'is_public' => (bool) ($data['is_public'] ?? true),
            'is_active' => true,
            'display_order' => (int) ($data['display_order'] ?? 0),
            'tier_options' => $data['feature_type'] === Feature::TYPE_TIER ? array_values((array) ($data['tier_options'] ?? [])) : null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->bootstrapper->attachMissingPlanEntitlements();
        $this->catalog->flush();
        $this->audit->catalog('feature', (int) $feature->id, 'feature_created', null, $feature->toArray(), $actor);

        return $feature;
    }

    /**
     * @param  array<string, mixed>  $data  validated by UpdateFeatureRequest
     */
    public function update(Feature $feature, array $data, User $actor): Feature
    {
        $before = $feature->toArray();
        $fill = array_intersect_key($data, array_flip(['name', 'description', 'category', 'module_key', 'unit', 'is_public', 'is_active', 'display_order', 'tier_options']));

        if (array_key_exists('is_active', $fill) && ! $fill['is_active'] && $feature->is_core) {
            throw SubscriptionException::changeNotAllowed('Core features cannot be deactivated.');
        }
        if (array_key_exists('tier_options', $fill) && $feature->feature_type !== Feature::TYPE_TIER) {
            unset($fill['tier_options']);
        }
        if (array_key_exists('feature_type', $data) && $data['feature_type'] !== $feature->feature_type) {
            if (PlanEntitlement::query()->where('feature_id', $feature->id)->exists()) {
                throw SubscriptionException::changeNotAllowed('The type of a feature used by plans cannot change.');
            }
            $fill['feature_type'] = $data['feature_type'];
        }
        $fill['updated_by'] = $actor->id;

        $feature->forceFill($fill)->save();
        $this->catalog->flush();
        $this->audit->catalog('feature', (int) $feature->id, 'feature_updated', $before, $feature->fresh()->toArray(), $actor);

        return $feature->fresh();
    }

    /**
     * @param  list<string>  $requiredCodes
     */
    public function setDependencies(Feature $feature, array $requiredCodes, User $actor): Feature
    {
        $requiredCodes = array_values(array_unique(array_map('strtoupper', $requiredCodes)));
        if (in_array($feature->code, $requiredCodes, true)) {
            throw SubscriptionException::catalogInvalid('A feature cannot depend on itself.', ['dependencies' => ['Self dependency.']]);
        }

        $required = Feature::query()->whereIn('code', $requiredCodes)->get();
        $unknown = array_values(array_diff($requiredCodes, $required->pluck('code')->all()));
        if ($unknown !== []) {
            throw SubscriptionException::catalogInvalid('Unknown features: '.implode(', ', $unknown), ['dependencies' => $unknown]);
        }
        if ($this->validator->createsCycle($feature->code, $requiredCodes)) {
            throw SubscriptionException::catalogInvalid('This dependency would create a cycle.', ['dependencies' => ['Circular dependency.']]);
        }

        $before = ['dependencies' => $feature->dependencies()->pluck('code')->all()];
        DB::transaction(function () use ($feature, $required): void {
            $feature->dependencies()->sync($required->pluck('id')->mapWithKeys(fn ($id) => [$id => ['dependency_type' => 'REQUIRES']])->all());
        });

        $this->catalog->flush();
        $this->audit->catalog('feature', (int) $feature->id, 'feature_dependencies_updated', $before, ['dependencies' => $requiredCodes], $actor);

        return $feature->fresh('dependencies');
    }
}
