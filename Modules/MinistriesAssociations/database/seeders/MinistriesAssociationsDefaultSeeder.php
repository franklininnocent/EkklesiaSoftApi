<?php

namespace Modules\MinistriesAssociations\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationCategory;
use Modules\MinistriesAssociations\Models\OrganizationType;
use Modules\MinistriesAssociations\Models\Position;

/**
 * Idempotent default Ministries & Associations data for a single tenant.
 *
 * Seeds only missing records (matched by tenant_id + code). Existing rows are
 * reused without field overwrites. Soft-deleted matches are restored and reused.
 */
class MinistriesAssociationsDefaultSeeder extends Seeder
{
    /**
     * @return array{categories_created:int,types_created:int,positions_created:int,organizations_created:int,categories_reused:int,types_reused:int,positions_reused:int,organizations_reused:int}
     */
    public function run(?int $tenantId = null, ?int $userId = null): array
    {
        $summary = [
            'categories_created' => 0,
            'types_created' => 0,
            'positions_created' => 0,
            'organizations_created' => 0,
            'categories_reused' => 0,
            'types_reused' => 0,
            'positions_reused' => 0,
            'organizations_reused' => 0,
        ];

        if (! $tenantId) {
            return $summary;
        }

        return DB::transaction(function () use ($tenantId, $userId, $summary) {
            $categories = [];
            foreach ($this->defaultCategories() as $index => $defaults) {
                [$category, $created] = $this->findOrCreateCategory($tenantId, $defaults, $index, $userId);
                $categories[$defaults['code']] = $category;
                $summary[$created ? 'categories_created' : 'categories_reused']++;
            }

            $types = [];
            foreach ($this->defaultTypes() as $index => $defaults) {
                [$type, $created] = $this->findOrCreateType($tenantId, $defaults, $index, $userId);
                $types[$defaults['code']] = $type;
                $summary[$created ? 'types_created' : 'types_reused']++;
            }

            foreach ($this->defaultPositions() as $index => $defaults) {
                [, $created] = $this->findOrCreatePosition($tenantId, $defaults, $index, $userId);
                $summary[$created ? 'positions_created' : 'positions_reused']++;
            }

            $associationCategory = $categories['association'] ?? null;
            $parishAssociationType = $types['parish_association'] ?? null;

            if ($associationCategory && $parishAssociationType) {
                [, $created] = $this->findOrCreateOrganization(
                    $tenantId,
                    $associationCategory->id,
                    $parishAssociationType->id,
                    $userId,
                );
                $summary[$created ? 'organizations_created' : 'organizations_reused']++;
            }

            return $summary;
        });
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    private function defaultCategories(): array
    {
        return [
            ['code' => 'ministry', 'name' => 'Ministry'],
            ['code' => 'association', 'name' => 'Association'],
        ];
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    private function defaultTypes(): array
    {
        return [
            ['code' => 'parish_ministry', 'name' => 'Parish Ministry'],
            ['code' => 'parish_association', 'name' => 'Parish Association'],
        ];
    }

    /**
     * @return list<array{code: string, name: string, single_occupancy: bool}>
     */
    private function defaultPositions(): array
    {
        return [
            ['code' => 'president', 'name' => 'President', 'single_occupancy' => true],
            ['code' => 'vice_president', 'name' => 'Vice President', 'single_occupancy' => true],
            ['code' => 'secretary', 'name' => 'Secretary', 'single_occupancy' => true],
            ['code' => 'joint_secretary', 'name' => 'Joint Secretary', 'single_occupancy' => true],
            ['code' => 'treasurer', 'name' => 'Treasurer', 'single_occupancy' => true],
            ['code' => 'coordinator', 'name' => 'Coordinator', 'single_occupancy' => true],
            ['code' => 'animator', 'name' => 'Animator', 'single_occupancy' => true],
            ['code' => 'member', 'name' => 'Member', 'single_occupancy' => false],
        ];
    }

    /**
     * @param  array{code: string, name: string}  $defaults
     * @return array{0: OrganizationCategory, 1: bool}
     */
    private function findOrCreateCategory(int $tenantId, array $defaults, int $displayOrder, ?int $userId): array
    {
        $existing = OrganizationCategory::withTrashed()
            ->forTenant($tenantId)
            ->where('code', $defaults['code'])
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return [$existing, false];
        }

        $category = OrganizationCategory::create([
            'tenant_id' => $tenantId,
            'code' => $defaults['code'],
            'name' => $defaults['name'],
            'description' => null,
            'is_system' => true,
            'is_active' => true,
            'display_order' => $displayOrder,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        return [$category, true];
    }

    /**
     * @param  array{code: string, name: string}  $defaults
     * @return array{0: OrganizationType, 1: bool}
     */
    private function findOrCreateType(int $tenantId, array $defaults, int $displayOrder, ?int $userId): array
    {
        $existing = OrganizationType::withTrashed()
            ->forTenant($tenantId)
            ->where('code', $defaults['code'])
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return [$existing, false];
        }

        $type = OrganizationType::create([
            'tenant_id' => $tenantId,
            'code' => $defaults['code'],
            'name' => $defaults['name'],
            'description' => null,
            'is_system' => true,
            'is_active' => true,
            'display_order' => $displayOrder,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        return [$type, true];
    }

    /**
     * @param  array{code: string, name: string, single_occupancy: bool}  $defaults
     * @return array{0: Position, 1: bool}
     */
    private function findOrCreatePosition(int $tenantId, array $defaults, int $displayOrder, ?int $userId): array
    {
        $existing = Position::withTrashed()
            ->forTenant($tenantId)
            ->where('code', $defaults['code'])
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return [$existing, false];
        }

        $position = Position::create([
            'tenant_id' => $tenantId,
            'code' => $defaults['code'],
            'name' => $defaults['name'],
            'single_occupancy' => $defaults['single_occupancy'],
            'is_system' => true,
            'is_active' => true,
            'display_order' => $displayOrder,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        return [$position, true];
    }

    /**
     * @return array{0: Organization, 1: bool}
     */
    private function findOrCreateOrganization(
        int $tenantId,
        string $categoryId,
        string $typeId,
        ?int $userId,
    ): array {
        $existing = Organization::withTrashed()
            ->forTenant($tenantId)
            ->where('code', 'YOUTH')
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return [$existing, false];
        }

        $organization = Organization::create([
            'tenant_id' => $tenantId,
            'category_id' => $categoryId,
            'type_id' => $typeId,
            'code' => 'YOUTH',
            'name' => 'Youth Association',
            'description' => 'Default parish youth association.',
            'status' => Organization::STATUS_ACTIVE,
            'allow_multi_role_holding' => false,
            'guests_can_hold_office' => false,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        return [$organization, true];
    }
}
