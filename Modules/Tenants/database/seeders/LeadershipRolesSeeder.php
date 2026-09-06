<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Tenants\Models\LeadershipRole;
use Modules\Tenants\Support\LeadershipRoleCategory;

class LeadershipRolesSeeder extends Seeder
{
    /**
     * @return list<array{title: string, category: string, hierarchical_level: int, allows_concurrent: bool, is_canonical_mandate: bool}>
     */
    public static function globalRoles(): array
    {
        return [
            ['title' => 'Diocesan Bishop', 'category' => LeadershipRoleCategory::CANONICAL_DIOCESAN, 'hierarchical_level' => 1, 'allows_concurrent' => false, 'is_canonical_mandate' => true],
            ['title' => 'Auxiliary Bishop', 'category' => LeadershipRoleCategory::CANONICAL_DIOCESAN, 'hierarchical_level' => 1, 'allows_concurrent' => false, 'is_canonical_mandate' => true],
            ['title' => 'Vicar General', 'category' => LeadershipRoleCategory::CANONICAL_DIOCESAN, 'hierarchical_level' => 1, 'allows_concurrent' => false, 'is_canonical_mandate' => true],
            ['title' => 'Vicar Forane', 'category' => LeadershipRoleCategory::CANONICAL_DIOCESAN, 'hierarchical_level' => 1, 'allows_concurrent' => false, 'is_canonical_mandate' => true],
            ['title' => 'Pastor', 'category' => LeadershipRoleCategory::PARISH_CLERGY, 'hierarchical_level' => 2, 'allows_concurrent' => false, 'is_canonical_mandate' => false],
            ['title' => 'Parochial Administrator', 'category' => LeadershipRoleCategory::PARISH_CLERGY, 'hierarchical_level' => 2, 'allows_concurrent' => false, 'is_canonical_mandate' => false],
            ['title' => 'Parochial Vicar', 'category' => LeadershipRoleCategory::PARISH_CLERGY, 'hierarchical_level' => 2, 'allows_concurrent' => true, 'is_canonical_mandate' => false],
            ['title' => 'Deacon', 'category' => LeadershipRoleCategory::PARISH_CLERGY, 'hierarchical_level' => 2, 'allows_concurrent' => true, 'is_canonical_mandate' => false],
            ['title' => 'Pastoral Council Chair', 'category' => LeadershipRoleCategory::PARISH_COUNCIL, 'hierarchical_level' => 3, 'allows_concurrent' => false, 'is_canonical_mandate' => false],
            ['title' => 'Finance Council Chair', 'category' => LeadershipRoleCategory::PARISH_COUNCIL, 'hierarchical_level' => 3, 'allows_concurrent' => false, 'is_canonical_mandate' => false],
            ['title' => 'Parish Council Member', 'category' => LeadershipRoleCategory::PARISH_COUNCIL, 'hierarchical_level' => 3, 'allows_concurrent' => true, 'is_canonical_mandate' => false],
            ['title' => 'Choir Director', 'category' => LeadershipRoleCategory::MINISTRY_PIOUS, 'hierarchical_level' => 4, 'allows_concurrent' => false, 'is_canonical_mandate' => false],
            ['title' => 'Youth Leader', 'category' => LeadershipRoleCategory::MINISTRY_PIOUS, 'hierarchical_level' => 4, 'allows_concurrent' => false, 'is_canonical_mandate' => false],
            ['title' => 'Catechetical Coordinator', 'category' => LeadershipRoleCategory::MINISTRY_PIOUS, 'hierarchical_level' => 4, 'allows_concurrent' => false, 'is_canonical_mandate' => false],
            ['title' => 'Ministry Head', 'category' => LeadershipRoleCategory::MINISTRY_PIOUS, 'hierarchical_level' => 4, 'allows_concurrent' => true, 'is_canonical_mandate' => false],
        ];
    }

    public function run(): void
    {
        foreach (self::globalRoles() as $role) {
            LeadershipRole::query()->firstOrCreate(
                [
                    'tenant_id' => null,
                    'title' => $role['title'],
                ],
                [
                    'id' => (string) Str::uuid(),
                    'category' => $role['category'],
                    'hierarchical_level' => $role['hierarchical_level'],
                    'allows_concurrent' => $role['allows_concurrent'],
                    'is_canonical_mandate' => $role['is_canonical_mandate'],
                    'is_active' => true,
                ]
            );
        }
    }
}
