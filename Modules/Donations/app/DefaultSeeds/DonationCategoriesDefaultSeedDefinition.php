<?php

namespace Modules\Donations\DefaultSeeds;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Donations\Models\DonationCategory;
use Modules\Donations\Services\DonationAuditService;
use Modules\Tenants\Contracts\TenantDefaultSeedDefinition;
use Modules\Tenants\DefaultSeeds\DefaultSeedStatus;
use Modules\Tenants\DefaultSeeds\Support\CanonicalCodeStatusCalculator;

class DonationCategoriesDefaultSeedDefinition implements TenantDefaultSeedDefinition
{
    public function __construct(
        private readonly DonationAuditService $auditService,
    ) {
    }

    public function id(): string
    {
        return 'donations.categories';
    }

    public function module(): string
    {
        return 'donations';
    }

    public function moduleLabel(): string
    {
        return 'Donations';
    }

    public function displayName(): string
    {
        return 'Offering categories';
    }

    public function description(): string
    {
        return 'Gift types volunteers pick during collection (Sunday, memorial, building fund, and more).';
    }

    public function sortOrder(): int
    {
        return 10;
    }

    public function requiredPermission(): string
    {
        return 'donations.manage';
    }

    public function featureKey(): string
    {
        return 'donations';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function catalog(int $tenantId): array
    {
        $defaults = $this->canonicalDefaults();
        $status = CanonicalCodeStatusCalculator::forModel(DonationCategory::class, $tenantId, $defaults);

        return $this->baseCatalog($status);
    }

    public function execute(int $tenantId, ?int $userId): array
    {
        $defaults = $this->canonicalDefaults();
        $before = CanonicalCodeStatusCalculator::forModel(DonationCategory::class, $tenantId, $defaults);

        if ($before['missing_count'] === 0) {
            return $this->resultPayload(
                DefaultSeedStatus::RESULT_ALREADY_INITIALIZED,
                0,
                0,
                0,
                'Recommended offering categories are already in place. Nothing new was added.',
            );
        }

        $created = 0;
        $skipped = 0;

        try {
            DB::transaction(function () use ($tenantId, $userId, $defaults, &$created, &$skipped) {
                $displayOrder = 0;
                foreach ($defaults as $row) {
                    [$wasCreated] = $this->findOrCreateCategory($tenantId, $userId, $row, $displayOrder);
                    if ($wasCreated) {
                        $created++;
                    } else {
                        $skipped++;
                    }
                    $displayOrder++;
                }
            });
        } catch (\Throwable $e) {
            report($e);

            return $this->resultPayload(
                DefaultSeedStatus::RESULT_FAILED,
                0,
                0,
                1,
                'Could not add offering categories. Please try again.',
            );
        }

        $this->auditService->log(
            $tenantId,
            'category.defaults_seeded',
            'donation_category',
            (string) $tenantId,
            null,
            ['created' => $created, 'skipped' => $skipped],
        );

        $after = CanonicalCodeStatusCalculator::forModel(DonationCategory::class, $tenantId, $defaults);
        $resultStatus = $after['missing_count'] === 0
            ? DefaultSeedStatus::RESULT_COMPLETED
            : DefaultSeedStatus::RESULT_PARTIALLY_COMPLETED;

        $message = match ($resultStatus) {
            DefaultSeedStatus::RESULT_COMPLETED => "Added {$created} offering categor".($created === 1 ? 'y' : 'ies').'. Nothing was changed.',
            default => "Added {$created} offering categor".($created === 1 ? 'y' : 'ies').'. Some recommended categories may still be missing.',
        };

        return $this->resultPayload($resultStatus, $created, $skipped, 0, $message);
    }

    /**
     * @return list<array{code: string, name: string, description?: string, is_tax_deductible?: bool}>
     */
    public function canonicalDefaults(): array
    {
        return [
            ['name' => 'General Church Donation', 'code' => 'GENERAL', 'description' => 'General voluntary offering', 'is_tax_deductible' => true],
            ['name' => 'Thanksgiving Offering', 'code' => 'THANKSGIVING', 'description' => 'Seasonal thanksgiving offering', 'is_tax_deductible' => true],
            ['name' => 'Memorial Donation', 'code' => 'MEMORIAL', 'description' => 'Donation in memory of a loved one', 'is_tax_deductible' => false],
            ['name' => 'Feast Contribution', 'code' => 'FEAST', 'description' => 'Parish feast or celebration contribution', 'is_tax_deductible' => false],
            ['name' => 'Charity Donation', 'code' => 'CHARITY', 'description' => 'Charitable outreach donation', 'is_tax_deductible' => true],
            ['name' => 'Building Fund Donation', 'code' => 'BUILDING', 'description' => 'Building or renovation fund', 'is_tax_deductible' => true],
            ['name' => 'Anonymous Donation', 'code' => 'ANONYMOUS', 'description' => 'Anonymous voluntary gift', 'is_tax_deductible' => false],
        ];
    }

    /**
     * @param  array{code: string, name: string, description?: string, is_tax_deductible?: bool}  $defaults
     * @return array{0: DonationCategory, 1: bool}
     */
    private function findOrCreateCategory(int $tenantId, ?int $userId, array $defaults, int $displayOrder): array
    {
        $existing = DonationCategory::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('code', $defaults['code'])
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return [$existing, false];
        }

        $category = DonationCategory::create([
            'tenant_id' => $tenantId,
            'code' => $defaults['code'],
            'name' => $defaults['name'],
            'description' => $defaults['description'] ?? null,
            'is_tax_deductible' => $defaults['is_tax_deductible'] ?? false,
            'active' => true,
            'created_by' => $userId,
            'updated_by' => $userId,
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
            'open_route' => '/donations/categories',
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
            return 'All recommended offering categories are in place.';
        }

        $suffix = $status['has_other_records']
            ? ' Your existing categories stay as they are.'
            : '';

        return "Adds {$missing} recommended offering categor".($missing === 1 ? 'y' : 'ies').".{$suffix}";
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
