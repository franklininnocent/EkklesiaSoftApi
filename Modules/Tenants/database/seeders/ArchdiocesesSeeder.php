<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Archdioceses Seeder
 * 
 * Seeds the archdioceses table with major archdioceses and dioceses worldwide.
 * This provides a standardized lookup table for ecclesiastical hierarchy.
 */
class ArchdiocesesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get denomination ID for Catholic (most archdioceses are Catholic)
        $catholicId = DB::table('denominations')->where('code', 'CATHOLIC')->value('id');
        $anglicanId = DB::table('denominations')->where('code', 'ANGLICAN')->value('id');
        $orthodoxId = DB::table('denominations')->where('code', 'ORTHODOX')->value('id');

        $archdioceses = [
            // United States - Catholic
            [
                'name' => 'Archdiocese of New York',
                'code' => 'NEW_YORK',
                'country' => 'United States',
                'region' => 'New York',
                'headquarters_city' => 'New York City',
                'denomination_id' => $catholicId,
                'parent_archdiocese_id' => null,
                'description' => 'One of the largest Catholic archdioceses in the United States',
                'website' => 'https://archny.org',
                'active' => 1,
            ],
            [
                'name' => 'Archdiocese of Los Angeles',
                'code' => 'LOS_ANGELES',
                'country' => 'United States',
                'region' => 'California',
                'headquarters_city' => 'Los Angeles',
                'denomination_id' => $catholicId,
                'parent_archdiocese_id' => null,
                'description' => 'The largest Catholic archdiocese in the United States',
                'website' => 'https://lacatholics.org',
                'active' => 1,
            ],
            [
                'name' => 'Archdiocese of Chicago',
                'code' => 'CHICAGO',
                'country' => 'United States',
                'region' => 'Illinois',
                'headquarters_city' => 'Chicago',
                'denomination_id' => $catholicId,
                'parent_archdiocese_id' => null,
                'description' => 'Major Catholic archdiocese in the Midwest',
                'website' => 'https://archchicago.org',
                'active' => 1,
            ],
            [
                'name' => 'Archdiocese of Washington',
                'code' => 'WASHINGTON_DC',
                'country' => 'United States',
                'region' => 'District of Columbia',
                'headquarters_city' => 'Washington',
                'denomination_id' => $catholicId,
                'parent_archdiocese_id' => null,
                'description' => 'Catholic archdiocese serving the nation\'s capital',
                'website' => 'https://adw.org',
                'active' => 1,
            ],
            [
                'name' => 'Archdiocese of Boston',
                'code' => 'BOSTON',
                'country' => 'United States',
                'region' => 'Massachusetts',
                'headquarters_city' => 'Boston',
                'denomination_id' => $catholicId,
                'parent_archdiocese_id' => null,
                'description' => 'Historic Catholic archdiocese in New England',
                'website' => 'https://www.bostoncatholic.org',
                'active' => 1,
            ],

            // United Kingdom - Anglican
            [
                'name' => 'Diocese of London',
                'code' => 'LONDON_ANGLICAN',
                'country' => 'United Kingdom',
                'region' => 'England',
                'headquarters_city' => 'London',
                'denomination_id' => $anglicanId,
                'parent_archdiocese_id' => null,
                'description' => 'Anglican Diocese of London',
                'website' => 'https://www.london.anglican.org',
                'active' => 1,
            ],
            [
                'name' => 'Archdiocese of Canterbury',
                'code' => 'CANTERBURY',
                'country' => 'United Kingdom',
                'region' => 'England',
                'headquarters_city' => 'Canterbury',
                'denomination_id' => $anglicanId,
                'parent_archdiocese_id' => null,
                'description' => 'The mother church of the worldwide Anglican Communion',
                'website' => 'https://www.canterburydiocese.org',
                'active' => 1,
            ],

            // India - Catholic
            [
                'name' => 'Archdiocese of Bangalore',
                'code' => 'BANGALORE',
                'country' => 'India',
                'region' => 'Karnataka',
                'headquarters_city' => 'Bangalore',
                'denomination_id' => $catholicId,
                'parent_archdiocese_id' => null,
                'description' => 'Major Catholic archdiocese in South India',
                'website' => 'https://www.bangalorearchdiocese.com',
                'active' => 1,
            ],
            [
                'name' => 'Archdiocese of Mumbai',
                'code' => 'MUMBAI',
                'country' => 'India',
                'region' => 'Maharashtra',
                'headquarters_city' => 'Mumbai',
                'denomination_id' => $catholicId,
                'parent_archdiocese_id' => null,
                'description' => 'Catholic archdiocese serving Mumbai and surrounding areas',
                'website' => 'https://www.bombaycatholicarchdiocese.com',
                'active' => 1,
            ],

            // Philippines - Catholic
            [
                'name' => 'Archdiocese of Manila',
                'code' => 'MANILA',
                'country' => 'Philippines',
                'region' => 'Metro Manila',
                'headquarters_city' => 'Manila',
                'denomination_id' => $catholicId,
                'parent_archdiocese_id' => null,
                'description' => 'The oldest archdiocese in the Philippines',
                'website' => 'https://www.rcam.org',
                'active' => 1,
            ],

            // Italy - Catholic
            [
                'name' => 'Archdiocese of Rome (Vatican)',
                'code' => 'ROME',
                'country' => 'Vatican City',
                'region' => 'Lazio',
                'headquarters_city' => 'Vatican City',
                'denomination_id' => $catholicId,
                'parent_archdiocese_id' => null,
                'description' => 'The Diocese of Rome, seat of the Pope',
                'website' => 'https://www.diocesidiroma.it',
                'active' => 1,
            ],
            [
                'name' => 'Archdiocese of Milan',
                'code' => 'MILAN',
                'country' => 'Italy',
                'region' => 'Lombardy',
                'headquarters_city' => 'Milan',
                'denomination_id' => $catholicId,
                'parent_archdiocese_id' => null,
                'description' => 'One of the largest Catholic archdioceses in Europe',
                'website' => 'https://www.chiesadimilano.it',
                'active' => 1,
            ],

            // Canada - Catholic
            [
                'name' => 'Archdiocese of Toronto',
                'code' => 'TORONTO',
                'country' => 'Canada',
                'region' => 'Ontario',
                'headquarters_city' => 'Toronto',
                'denomination_id' => $catholicId,
                'parent_archdiocese_id' => null,
                'description' => 'Largest Catholic archdiocese in English-speaking Canada',
                'website' => 'https://www.archtoronto.org',
                'active' => 1,
            ],

            // Australia - Catholic
            [
                'name' => 'Archdiocese of Sydney',
                'code' => 'SYDNEY',
                'country' => 'Australia',
                'region' => 'New South Wales',
                'headquarters_city' => 'Sydney',
                'denomination_id' => $catholicId,
                'parent_archdiocese_id' => null,
                'description' => 'The oldest Catholic diocese in Australia',
                'website' => 'https://www.sydneycatholic.org',
                'active' => 1,
            ],

            // Greece - Orthodox
            [
                'name' => 'Archdiocese of Athens',
                'code' => 'ATHENS',
                'country' => 'Greece',
                'region' => 'Attica',
                'headquarters_city' => 'Athens',
                'denomination_id' => $orthodoxId,
                'parent_archdiocese_id' => null,
                'description' => 'Greek Orthodox Archdiocese of Athens',
                'website' => null,
                'active' => 1,
            ],

            // Nigeria - Catholic
            [
                'name' => 'Archdiocese of Lagos',
                'code' => 'LAGOS',
                'country' => 'Nigeria',
                'region' => 'Lagos State',
                'headquarters_city' => 'Lagos',
                'denomination_id' => $catholicId,
                'parent_archdiocese_id' => null,
                'description' => 'Major Catholic archdiocese in West Africa',
                'website' => 'https://www.lagosarchdiocese.org',
                'active' => 1,
            ],

            // Mexico - Catholic
            [
                'name' => 'Archdiocese of Mexico City',
                'code' => 'MEXICO_CITY',
                'country' => 'Mexico',
                'region' => 'Mexico City',
                'headquarters_city' => 'Mexico City',
                'denomination_id' => $catholicId,
                'parent_archdiocese_id' => null,
                'description' => 'The oldest diocese in the Americas',
                'website' => 'https://www.arquidiocesismexico.org.mx',
                'active' => 1,
            ],

            // Brazil - Catholic
            [
                'name' => 'Archdiocese of São Paulo',
                'code' => 'SAO_PAULO',
                'country' => 'Brazil',
                'region' => 'São Paulo',
                'headquarters_city' => 'São Paulo',
                'denomination_id' => $catholicId,
                'parent_archdiocese_id' => null,
                'description' => 'One of the largest Catholic archdioceses in the world',
                'website' => 'https://www.arquisp.org.br',
                'active' => 1,
            ],

            // General/Other
            [
                'name' => 'Independent Church',
                'code' => 'INDEPENDENT',
                'country' => 'Various',
                'region' => null,
                'headquarters_city' => null,
                'denomination_id' => null,
                'parent_archdiocese_id' => null,
                'description' => 'For churches not affiliated with any archdiocese',
                'website' => null,
                'active' => 1,
            ],
        ];

        $now = Carbon::now();

        foreach ($archdioceses as &$archdiocese) {
            $archdiocese['created_at'] = $now;
            $archdiocese['updated_at'] = $now;
        }

        DB::table('archdioceses')->insert($archdioceses);

        $this->command->info('Archdioceses seeded successfully!');
    }
}
