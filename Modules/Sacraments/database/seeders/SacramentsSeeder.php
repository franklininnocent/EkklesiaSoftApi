<?php

namespace Modules\Sacraments\database\seeders;

use Illuminate\Database\Seeder;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentType;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;

class SacramentsSeeder extends Seeder
{
    public function run(): void
    {
        $types = SacramentType::all();
        if ($types->isEmpty()) {
            return;
        }

        $tenantId = Tenant::query()->value('id');
        if (! $tenantId) {
            $this->command?->warn('Skipping SacramentsSeeder: no tenant exists. Create a tenant via the UI first.');

            return;
        }

        $actorId = User::query()->value('id');

        for ($i = 0; $i < 20; $i++) {
            Sacrament::factory()->create([
                'tenant_id' => $tenantId,
                'sacrament_type_id' => $types->random()->id,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
        }
    }
}
