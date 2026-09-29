<?php

namespace Modules\MinistriesAssociations\DefaultSeeds;

use Illuminate\Database\Eloquent\Model;
use Modules\MinistriesAssociations\DefaultSeeds\Support\MinistriesTaxonomyDefaultSeedTrait;
use Modules\MinistriesAssociations\Models\OrganizationCategory;
use Modules\Tenants\Contracts\TenantDefaultSeedDefinition;

class OrganizationCategoriesDefaultSeedDefinition implements TenantDefaultSeedDefinition
{
    use MinistriesTaxonomyDefaultSeedTrait;

    public function id(): string
    {
        return 'ministries.categories';
    }

    public function module(): string
    {
        return 'ministries';
    }

    public function moduleLabel(): string
    {
        return 'Ministries & Associations';
    }

    public function displayName(): string
    {
        return 'Ministry categories';
    }

    public function description(): string
    {
        return 'Groups such as spiritual, youth, and charitable work.';
    }

    public function sortOrder(): int
    {
        return 20;
    }

    public function requiredPermission(): string
    {
        return 'ministries.configure';
    }

    public function featureKey(): string
    {
        return 'ministries_associations';
    }

    public function dependsOn(): array
    {
        return [];
    }

    protected function modelClass(): string
    {
        return OrganizationCategory::class;
    }

    protected function auditEvent(): string
    {
        return 'category.defaults_seeded';
    }

    protected function auditTargetType(): string
    {
        return 'organization_category';
    }

    protected function openRoute(): string
    {
        return '/ministries/settings';
    }

    protected function openQuery(): ?array
    {
        return ['tab' => 'categories'];
    }

    protected function canonicalDefaults(): array
    {
        return [
            ['code' => 'spiritual', 'name' => 'Spiritual'],
            ['code' => 'liturgical', 'name' => 'Liturgical'],
            ['code' => 'charitable', 'name' => 'Charitable'],
            ['code' => 'educational', 'name' => 'Educational'],
            ['code' => 'youth', 'name' => 'Youth'],
            ['code' => 'social', 'name' => 'Social'],
            ['code' => 'administrative', 'name' => 'Administrative'],
        ];
    }

    protected function createRow(int $tenantId, ?int $userId, array $defaults, int $displayOrder): Model
    {
        return OrganizationCategory::create([
            'tenant_id' => $tenantId,
            'code' => $defaults['code'],
            'name' => $defaults['name'],
            'description' => $defaults['description'] ?? null,
            'is_system' => true,
            'is_active' => true,
            'display_order' => $displayOrder,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }
}
