<?php

namespace Modules\Family\database\seeders;

use Illuminate\Database\Seeder;
use Modules\Tenants\Models\Tenant;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\BCC\Models\BCC;

class FamiliesSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->first();
        if (!$tenant) return;

        // Ensure some BCCs exist for assignment
        $bccs = BCC::factory()->count(3)->create(['tenant_id' => $tenant->id]);

        // Create 10 families with 3-5 members each
        Family::factory()->count(10)->create([
            'tenant_id' => $tenant->id,
            'bcc_id' => fn () => $bccs->random()->id,
        ])->each(function (Family $family) {
            FamilyMember::factory()->count(rand(3,5))->create(['family_id' => $family->id]);
        });
    }
}


