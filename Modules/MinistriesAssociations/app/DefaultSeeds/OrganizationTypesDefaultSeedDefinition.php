<?php

namespace Modules\MinistriesAssociations\DefaultSeeds;

use Illuminate\Database\Eloquent\Model;
use Modules\MinistriesAssociations\DefaultSeeds\Support\MinistriesTaxonomyDefaultSeedTrait;
use Modules\MinistriesAssociations\Models\OrganizationType;
use Modules\Tenants\Contracts\TenantDefaultSeedDefinition;

class OrganizationTypesDefaultSeedDefinition implements TenantDefaultSeedDefinition
{
    use MinistriesTaxonomyDefaultSeedTrait;

    public function id(): string
    {
        return 'ministries.types';
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
        return 'Organization types';
    }

    public function description(): string
    {
        return 'Whether a group is a ministry, choir, committee, or similar.';
    }

    public function sortOrder(): int
    {
        return 21;
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
        return OrganizationType::class;
    }

    protected function auditEvent(): string
    {
        return 'type.defaults_seeded';
    }

    protected function auditTargetType(): string
    {
        return 'organization_type';
    }

    protected function openRoute(): string
    {
        return '/ministries/settings';
    }

    protected function openQuery(): ?array
    {
        return ['tab' => 'types'];
    }

    protected function canonicalDefaults(): array
    {
        return [
            ['code' => 'ministry', 'name' => 'Ministry'],
            ['code' => 'association', 'name' => 'Association'],
            ['code' => 'society', 'name' => 'Society'],
            ['code' => 'fellowship', 'name' => 'Fellowship'],
            ['code' => 'committee', 'name' => 'Committee'],
            ['code' => 'choir', 'name' => 'Choir'],
            ['code' => 'prayer_group', 'name' => 'Prayer Group'],
        ];
    }

    protected function createRow(int $tenantId, ?int $userId, array $defaults, int $displayOrder): Model
    {
        return OrganizationType::create([
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
