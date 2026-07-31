<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Ecclesiastical Titles Seeder
 * 
 * Seeds the ecclesiastical_titles table with Catholic ecclesiastical titles.
 */
class EcclesiasticalTitlesSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        
        $titles = [
            [
                'title' => 'Pope',
                'abbreviation' => 'Pope',
                'description' => 'Supreme Pontiff of the Universal Church, Bishop of Rome',
                'hierarchy_level' => 1,
                'display_order' => 1,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'Cardinal',
                'abbreviation' => 'Card.',
                'description' => 'Prince of the Church, member of the College of Cardinals',
                'hierarchy_level' => 2,
                'display_order' => 2,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'Archbishop',
                'abbreviation' => 'Abp.',
                'description' => 'Metropolitan Archbishop or Archbishop of an Archdiocese',
                'hierarchy_level' => 3,
                'display_order' => 3,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'Bishop',
                'abbreviation' => 'Bp.',
                'description' => 'Diocesan Bishop, ordinary of a diocese',
                'hierarchy_level' => 4,
                'display_order' => 4,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'Coadjutor Archbishop',
                'abbreviation' => 'Coadj. Abp.',
                'description' => 'Archbishop designated as successor to current archbishop with right of succession',
                'hierarchy_level' => 3,
                'display_order' => 5,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'Coadjutor Bishop',
                'abbreviation' => 'Coadj. Bp.',
                'description' => 'Bishop designated as successor to current bishop with right of succession',
                'hierarchy_level' => 4,
                'display_order' => 6,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'Auxiliary Bishop',
                'abbreviation' => 'Aux. Bp.',
                'description' => 'Assistant bishop without right of succession',
                'hierarchy_level' => 5,
                'display_order' => 7,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'Archbishop Emeritus',
                'abbreviation' => 'Abp. Emer.',
                'description' => 'Retired Archbishop',
                'hierarchy_level' => 3,
                'display_order' => 8,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'Bishop Emeritus',
                'abbreviation' => 'Bp. Emer.',
                'description' => 'Retired Bishop',
                'hierarchy_level' => 4,
                'display_order' => 9,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('ecclesiastical_titles')->insert($titles);

        $this->command->info('✅ Ecclesiastical titles seeded successfully!');
    }
}

