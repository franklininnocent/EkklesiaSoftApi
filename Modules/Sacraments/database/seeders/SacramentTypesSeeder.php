<?php

namespace Modules\Sacraments\database\seeders;

use Illuminate\Database\Seeder;
use Modules\Sacraments\Models\SacramentType;

class SacramentTypesSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['name' => 'Baptism', 'code' => 'baptism', 'category' => 'initiation'],
            ['name' => 'Confirmation', 'code' => 'confirmation', 'category' => 'initiation'],
            ['name' => 'Eucharist', 'code' => 'eucharist', 'category' => 'initiation'],
            ['name' => 'Reconciliation', 'code' => 'reconciliation', 'category' => 'healing'],
            ['name' => 'Anointing of the Sick', 'code' => 'anointing_sick', 'category' => 'healing'],
            ['name' => 'Marriage', 'code' => 'marriage', 'category' => 'service'],
            ['name' => 'Holy Orders', 'code' => 'holy_orders', 'category' => 'service'],
        ];

        foreach ($defaults as $type) {
            SacramentType::updateOrCreate(
                ['code' => $type['code']],
                ['name' => $type['name'], 'category' => $type['category']]
            );
        }
    }
}
