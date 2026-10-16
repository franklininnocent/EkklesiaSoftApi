<?php

namespace Modules\MinistriesAssociations\DefaultSeeds;

use Illuminate\Database\Eloquent\Model;
use Modules\MinistriesAssociations\DefaultSeeds\Support\MinistriesTaxonomyDefaultSeedTrait;
use Modules\MinistriesAssociations\Models\Position;
use Modules\Tenants\Contracts\TenantDefaultSeedDefinition;

class PositionsDefaultSeedDefinition implements TenantDefaultSeedDefinition
{
    use MinistriesTaxonomyDefaultSeedTrait;

    public function id(): string
    {
        return 'ministries.positions';
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
        return 'Leadership positions';
    }

    public function description(): string
    {
        return 'Offices such as president, secretary, treasurer, and committee member.';
    }

    public function sortOrder(): int
    {
        return 22;
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
        return Position::class;
    }

    protected function auditEvent(): string
    {
        return 'position.defaults_seeded';
    }

    protected function auditTargetType(): string
    {
        return 'position';
    }

    protected function openRoute(): string
    {
        return '/ministries/settings';
    }

    protected function openQuery(): ?array
    {
        return ['tab' => 'positions'];
    }

    protected function canonicalDefaults(): array
    {
        return [
            ['code' => 'president', 'name' => 'President', 'single_occupancy' => true],
            ['code' => 'vice_president', 'name' => 'Vice President', 'single_occupancy' => true],
            ['code' => 'secretary', 'name' => 'Secretary', 'single_occupancy' => true],
            ['code' => 'joint_secretary', 'name' => 'Joint Secretary', 'single_occupancy' => true],
            ['code' => 'treasurer', 'name' => 'Treasurer', 'single_occupancy' => true],
            ['code' => 'coordinator', 'name' => 'Coordinator', 'single_occupancy' => true],
            ['code' => 'committee_member', 'name' => 'Committee Member', 'single_occupancy' => false],
            ['code' => 'member', 'name' => 'Member', 'single_occupancy' => false],
        ];
    }

    protected function createRow(int $tenantId, ?int $userId, array $defaults, int $displayOrder): Model
    {
        return Position::create([
            'tenant_id' => $tenantId,
            'code' => $defaults['code'],
            'name' => $defaults['name'],
            'single_occupancy' => $defaults['single_occupancy'] ?? false,
            'is_system' => true,
            'is_active' => true,
            'display_order' => $displayOrder,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }
}
