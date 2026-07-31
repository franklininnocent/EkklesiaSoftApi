<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Denominations Seeder
 * 
 * Seeds the denominations table with major Christian denominations.
 * This provides a standardized lookup table for church classification.
 */
class DenominationsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $denominations = [
            // Catholic
            [
                'name' => 'Roman Catholic',
                'code' => 'CATHOLIC',
                'description' => 'The Roman Catholic Church, headed by the Pope in Rome',
                'active' => 1,
                'display_order' => 1,
            ],
            [
                'name' => 'Eastern Orthodox',
                'code' => 'ORTHODOX',
                'description' => 'Eastern Orthodox Churches including Greek, Russian, and other Orthodox traditions',
                'active' => 1,
                'display_order' => 2,
            ],
            [
                'name' => 'Oriental Orthodox',
                'code' => 'ORIENTAL_ORTHODOX',
                'description' => 'Oriental Orthodox Churches including Coptic, Ethiopian, Armenian traditions',
                'active' => 1,
                'display_order' => 3,
            ],
            
            // Eastern Catholic Churches (Indian Rites)
            [
                'name' => 'Syro-Malabar Catholic',
                'code' => 'SYRO_MALABAR',
                'description' => 'Syro-Malabar Catholic Church, an Eastern Catholic Church in India with Syriac liturgical tradition',
                'active' => 1,
                'display_order' => 31,
            ],
            [
                'name' => 'Syro-Malankara Catholic',
                'code' => 'SYRO_MALANKARA',
                'description' => 'Syro-Malankara Catholic Church, an Eastern Catholic Church in India with West Syriac liturgical tradition',
                'active' => 1,
                'display_order' => 32,
            ],
            [
                'name' => 'Mar Thoma Syrian Church',
                'code' => 'MAR_THOMA',
                'description' => 'Mar Thoma Syrian Church, a reformed Oriental Orthodox church in India',
                'active' => 1,
                'display_order' => 33,
            ],
            
            // Protestant - Major Denominations
            [
                'name' => 'Anglican/Episcopal',
                'code' => 'ANGLICAN',
                'description' => 'Anglican Communion and Episcopal Churches',
                'active' => 1,
                'display_order' => 4,
            ],
            [
                'name' => 'Lutheran',
                'code' => 'LUTHERAN',
                'description' => 'Lutheran Churches following Martin Luther\'s teachings',
                'active' => 1,
                'display_order' => 5,
            ],
            [
                'name' => 'Presbyterian',
                'code' => 'PRESBYTERIAN',
                'description' => 'Presbyterian and Reformed Churches',
                'active' => 1,
                'display_order' => 6,
            ],
            [
                'name' => 'Methodist',
                'code' => 'METHODIST',
                'description' => 'Methodist and Wesleyan Churches',
                'active' => 1,
                'display_order' => 7,
            ],
            [
                'name' => 'Baptist',
                'code' => 'BAPTIST',
                'description' => 'Baptist Churches emphasizing believer\'s baptism',
                'active' => 1,
                'display_order' => 8,
            ],
            
            // Pentecostal & Charismatic
            [
                'name' => 'Pentecostal',
                'code' => 'PENTECOSTAL',
                'description' => 'Pentecostal Churches emphasizing the gifts of the Holy Spirit',
                'active' => 1,
                'display_order' => 9,
            ],
            [
                'name' => 'Assemblies of God',
                'code' => 'AOG',
                'description' => 'Assemblies of God Pentecostal denomination',
                'active' => 1,
                'display_order' => 10,
            ],
            [
                'name' => 'Charismatic',
                'code' => 'CHARISMATIC',
                'description' => 'Charismatic and Neo-Charismatic Churches',
                'active' => 1,
                'display_order' => 11,
            ],
            
            // Other Protestant
            [
                'name' => 'Evangelical',
                'code' => 'EVANGELICAL',
                'description' => 'Evangelical Churches emphasizing the gospel and evangelism',
                'active' => 1,
                'display_order' => 12,
            ],
            [
                'name' => 'Congregational',
                'code' => 'CONGREGATIONAL',
                'description' => 'Congregational Churches with autonomous local governance',
                'active' => 1,
                'display_order' => 13,
            ],
            [
                'name' => 'Adventist',
                'code' => 'ADVENTIST',
                'description' => 'Seventh-day Adventist and other Adventist Churches',
                'active' => 1,
                'display_order' => 14,
            ],
            [
                'name' => 'Non-Denominational',
                'code' => 'NON_DENOM',
                'description' => 'Independent, non-denominational Christian Churches',
                'active' => 1,
                'display_order' => 15,
            ],
            
            // Historical Churches
            [
                'name' => 'Coptic',
                'code' => 'COPTIC',
                'description' => 'Coptic Orthodox Church of Alexandria',
                'active' => 1,
                'display_order' => 16,
            ],
            [
                'name' => 'Armenian Apostolic',
                'code' => 'ARMENIAN',
                'description' => 'Armenian Apostolic Church',
                'active' => 1,
                'display_order' => 17,
            ],
            
            // Other
            [
                'name' => 'Interdenominational',
                'code' => 'INTER_DENOM',
                'description' => 'Churches incorporating multiple denominational traditions',
                'active' => 1,
                'display_order' => 18,
            ],
            [
                'name' => 'Other',
                'code' => 'OTHER',
                'description' => 'Other Christian denominations not listed above',
                'active' => 1,
                'display_order' => 99,
            ],
        ];

        $now = Carbon::now();

        foreach ($denominations as &$denomination) {
            $denomination['created_at'] = $now;
            $denomination['updated_at'] = $now;
        }

        DB::table('denominations')->insert($denominations);

        $this->command->info('Denominations seeded successfully!');
    }
}
