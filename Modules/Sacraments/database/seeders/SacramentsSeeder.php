<?php

namespace Modules\Sacraments\database\seeders;

use Illuminate\Database\Seeder;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentType;

class SacramentsSeeder extends Seeder
{
    public function run(): void
    {
        $types = SacramentType::all();
        if ($types->isEmpty()) return;

        // Create 20 random sacraments
        for ($i = 0; $i < 20; $i++) {
            Sacrament::factory()->create([
                'sacrament_type_id' => $types->random()->id,
            ]);
        }
    }
}
