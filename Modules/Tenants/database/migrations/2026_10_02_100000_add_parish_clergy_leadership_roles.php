<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Tenants\Database\Seeders\LeadershipRolesSeeder;
use Modules\Tenants\Models\LeadershipRole;
use Modules\Tenants\Support\LeadershipRoleCategory;

return new class extends Migration
{
    public function up(): void
    {
        LeadershipRolesSeeder::sync([
            ['title' => 'Parish Priest', 'category' => LeadershipRoleCategory::PARISH_CLERGY, 'hierarchical_level' => 2, 'allows_concurrent' => false, 'is_canonical_mandate' => false],
            ['title' => 'Assistant Parish Priest', 'category' => LeadershipRoleCategory::PARISH_CLERGY, 'hierarchical_level' => 2, 'allows_concurrent' => true, 'is_canonical_mandate' => false],
            ['title' => 'Joint Parish Priest', 'category' => LeadershipRoleCategory::PARISH_CLERGY, 'hierarchical_level' => 2, 'allows_concurrent' => true, 'is_canonical_mandate' => false],
        ]);
    }

    public function down(): void
    {
        LeadershipRole::query()
            ->whereNull('tenant_id')
            ->whereIn('normalized_title', [
                'parish priest',
                'assistant parish priest',
                'joint parish priest',
            ])
            ->whereDoesntHave('assignments')
            ->delete();
    }
};
