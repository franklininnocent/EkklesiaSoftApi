<?php

namespace Modules\Sacraments\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Seed Sacraments Module Permissions
 */
class SacramentPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $permissions = [
            [
                'name' => 'sacraments.view',
                'display_name' => 'View Sacraments',
                'description' => 'View sacramental records',
                'module' => 'sacraments',
                'category' => 'sacraments',
                'tenant_id' => null,
                'is_custom' => 0,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'sacraments.create',
                'display_name' => 'Create Sacraments',
                'description' => 'Create new sacramental records',
                'module' => 'sacraments',
                'category' => 'sacraments',
                'tenant_id' => null,
                'is_custom' => 0,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'sacraments.edit',
                'display_name' => 'Edit Sacraments',
                'description' => 'Edit existing sacramental records',
                'module' => 'sacraments',
                'category' => 'sacraments',
                'tenant_id' => null,
                'is_custom' => 0,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'sacraments.delete',
                'display_name' => 'Delete Sacraments',
                'description' => 'Delete sacramental records',
                'module' => 'sacraments',
                'category' => 'sacraments',
                'tenant_id' => null,
                'is_custom' => 0,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'sacraments.export',
                'display_name' => 'Export Sacraments',
                'description' => 'Export sacramental records',
                'module' => 'sacraments',
                'category' => 'sacraments',
                'tenant_id' => null,
                'is_custom' => 0,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($permissions as $permission) {
            // Check if permission already exists
            $exists = DB::table('permissions')->where('name', $permission['name'])->exists();
            
            if (!$exists) {
                DB::table('permissions')->insert($permission);
                $this->command->info("✅ Created permission: {$permission['name']}");
            } else {
                $this->command->info("⏭️  Permission already exists: {$permission['name']}");
            }
        }

        $this->command->info('✅ Sacraments permissions seeded successfully');
    }
}
