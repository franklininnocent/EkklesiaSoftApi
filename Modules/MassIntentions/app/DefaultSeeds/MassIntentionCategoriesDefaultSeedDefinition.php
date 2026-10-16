<?php

namespace Modules\MassIntentions\DefaultSeeds;

use Illuminate\Support\Facades\DB;
use Modules\MassIntentions\Models\MassIntentionCategory;
use Modules\MassIntentions\Services\MassIntentionAuditService;
use Modules\Tenants\Contracts\TenantDefaultSeedDefinition;
use Modules\Tenants\DefaultSeeds\DefaultSeedStatus;
use Modules\Tenants\DefaultSeeds\Support\CanonicalCodeStatusCalculator;

class MassIntentionCategoriesDefaultSeedDefinition implements TenantDefaultSeedDefinition
{
    public function __construct(
        private readonly MassIntentionAuditService $auditService,
    ) {
    }

    public function id(): string
    {
        return 'mass_intentions.categories';
    }

    public function module(): string
    {
        return 'mass_intentions';
    }

    public function moduleLabel(): string
    {
        return 'Mass Intentions';
    }

    public function displayName(): string
    {
        return 'Mass intention types';
    }

    public function description(): string
    {
        return 'Standard intention categories parish staff pick when recording a Mass intention.';
    }

    public function sortOrder(): int
    {
        return 10;
    }

    public function requiredPermission(): string
    {
        return 'mass.intentions.configure';
    }

    public function featureKey(): string
    {
        return 'MASS_INTENTIONS';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function catalog(int $tenantId): array
    {
        $defaults = $this->canonicalDefaults();
        $status = CanonicalCodeStatusCalculator::forModel(MassIntentionCategory::class, $tenantId, $defaults);

        return $this->baseCatalog($status);
    }

    public function execute(int $tenantId, ?int $userId): array
    {
        $defaults = $this->canonicalDefaults();
        $before = CanonicalCodeStatusCalculator::forModel(MassIntentionCategory::class, $tenantId, $defaults);

        if ($before['missing_count'] === 0) {
            return $this->resultPayload(
                DefaultSeedStatus::RESULT_ALREADY_INITIALIZED,
                0,
                0,
                0,
                'Recommended Mass intention types are already in place.',
            );
        }

        $created = 0;
        $skipped = 0;

        try {
            DB::transaction(function () use ($tenantId, $userId, $defaults, &$created, &$skipped) {
                $order = 0;
                foreach ($defaults as $row) {
                    [$wasCreated] = $this->findOrCreateCategory($tenantId, $userId, $row, $order);
                    if ($wasCreated) {
                        $created++;
                    } else {
                        $skipped++;
                    }
                    $order++;
                }
            });
        } catch (\Throwable $e) {
            report($e);

            return $this->resultPayload(
                DefaultSeedStatus::RESULT_FAILED,
                0,
                0,
                1,
                'Could not add Mass intention types. Please try again.',
            );
        }

        $after = CanonicalCodeStatusCalculator::forModel(MassIntentionCategory::class, $tenantId, $defaults);
        $resultStatus = $after['missing_count'] === 0
            ? DefaultSeedStatus::RESULT_COMPLETED
            : DefaultSeedStatus::RESULT_PARTIALLY_COMPLETED;

        $message = $created > 0
            ? "Added {$created} Mass intention type".($created === 1 ? '' : 's').'.'
            : 'Mass intention types were already present.';

        return $this->resultPayload($resultStatus, $created, $skipped, 0, $message);
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function canonicalDefaults(): array
    {
        return [
            ['code' => 'FAITHFUL_DEPARTED', 'name' => 'For the Faithful Departed'],
            ['code' => 'THANKSGIVING', 'name' => 'Thanksgiving'],
            ['code' => 'SICK_HEALING', 'name' => 'For the Sick and Healing'],
            ['code' => 'FAMILY', 'name' => 'For Family'],
            ['code' => 'MARRIAGE', 'name' => 'For Marriage'],
            ['code' => 'CHILDREN', 'name' => 'For Children'],
            ['code' => 'STUDENTS_EDUCATION', 'name' => 'For Students and Education'],
            ['code' => 'EMPLOYMENT_BUSINESS', 'name' => 'For Employment and Business'],
            ['code' => 'VOCATIONS', 'name' => 'For Vocations'],
            ['code' => 'PRIESTS_RELIGIOUS', 'name' => 'For Priests and Religious'],
            ['code' => 'CHURCH', 'name' => 'For the Church'],
            ['code' => 'PEACE_RECONCILIATION', 'name' => 'For Peace and Reconciliation'],
            ['code' => 'GUIDANCE_PROTECTION', 'name' => 'For Guidance and Protection'],
            ['code' => 'SPECIAL_NEEDS', 'name' => 'For Special Needs'],
            ['code' => 'VARIOUS_NEEDS', 'name' => 'For Various Needs and Occasions'],
            ['code' => 'PARISH_COMMUNITY', 'name' => 'For the Parish Community'],
            ['code' => 'HOLY_FATHER_BISHOPS', 'name' => 'For the Holy Father and Bishops'],
            ['code' => 'SPECIAL_INTENTION', 'name' => 'For a Special Intention'],
        ];
    }

    /**
     * @param  array{code: string, name: string}  $defaults
     * @return array{0: MassIntentionCategory, 1: bool}
     */
    private function findOrCreateCategory(int $tenantId, ?int $userId, array $defaults, int $displayOrder): array
    {
        $existing = MassIntentionCategory::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('code', $defaults['code'])
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return [$existing, false];
        }

        $category = MassIntentionCategory::query()->create([
            'tenant_id' => $tenantId,
            'code' => $defaults['code'],
            'name' => $defaults['name'],
            'active' => true,
            'sort_order' => $displayOrder,
            'created_by_user_id' => $userId,
            'updated_by_user_id' => $userId,
        ]);

        return [$category, true];
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function baseCatalog(array $status): array
    {
        return [
            'id' => $this->id(),
            'module' => $this->module(),
            'module_label' => $this->moduleLabel(),
            'display_name' => $this->displayName(),
            'description' => $this->description(),
            'sort_order' => $this->sortOrder(),
            'depends_on' => $this->dependsOn(),
            'required_permission' => $this->requiredPermission(),
            'feature_key' => $this->featureKey(),
            'execution_strategy' => 'ADD_MISSING_DEFAULTS',
            'supports_preview' => true,
            'open_route' => '/mass-intentions/settings',
            'expected_count' => $status['expected_count'],
            'matched_count' => $status['matched_count'],
            'missing_count' => $status['missing_count'],
            'has_other_records' => $status['has_other_records'],
            'missing_names' => $status['missing_names'],
            'status' => $status['status'],
            'impact' => $this->impactText($status),
        ];
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function impactText(array $status): string
    {
        $missing = (int) $status['missing_count'];
        if ($missing === 0) {
            return 'All recommended Mass intention types are in place.';
        }

        return "Adds {$missing} recommended Mass intention type".($missing === 1 ? '' : 's').'.';
    }

    /**
     * @return array<string, mixed>
     */
    private function resultPayload(
        string $resultStatus,
        int $created,
        int $skipped,
        int $failed,
        string $message,
    ): array {
        return [
            'id' => $this->id(),
            'display_name' => $this->displayName(),
            'result_status' => $resultStatus,
            'created_count' => $created,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
            'message' => $message,
            'dependencies' => [],
        ];
    }
}
