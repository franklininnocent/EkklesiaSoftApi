<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Religious Orders Seeder
 * 
 * Seeds the religious_orders table with major Catholic religious orders
 * and congregations, with focus on those present in India and Tamil Nadu.
 */
class ReligiousOrdersSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        
        $orders = [
            // Major Religious Orders (Male)
            [
                'abbreviation' => 'SJ',
                'full_name' => 'Society of Jesus',
                'common_name' => 'Jesuits',
                'type' => 'society',
                'branch' => 'male',
                'description' => 'Founded by St. Ignatius of Loyola, focused on education, missionary work, and spiritual formation',
                'founded_year' => 1540,
                'founder' => 'St. Ignatius of Loyola',
                'website' => 'https://www.jesuits.org',
                'display_order' => 1,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'abbreviation' => 'OFM',
                'full_name' => 'Order of Friars Minor',
                'common_name' => 'Franciscans',
                'type' => 'order',
                'branch' => 'male',
                'description' => 'Founded by St. Francis of Assisi, dedicated to poverty, peace, and service',
                'founded_year' => 1209,
                'founder' => 'St. Francis of Assisi',
                'website' => 'https://www.ofm.org',
                'display_order' => 2,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'abbreviation' => 'OMI',
                'full_name' => 'Oblates of Mary Immaculate',
                'common_name' => 'OMI Fathers',
                'type' => 'congregation',
                'branch' => 'male',
                'description' => 'Missionary congregation founded by St. Eugene de Mazenod',
                'founded_year' => 1816,
                'founder' => 'St. Eugene de Mazenod',
                'website' => 'https://www.omiworld.org',
                'display_order' => 3,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'abbreviation' => 'MSFS',
                'full_name' => 'Missionaries of St. Francis de Sales',
                'common_name' => 'Fransalians',
                'type' => 'congregation',
                'branch' => 'male',
                'description' => 'Founded by Fr. Peter Mermier, strong presence in India',
                'founded_year' => 1838,
                'founder' => 'Fr. Peter Mermier',
                'website' => 'https://www.msfs.org',
                'display_order' => 4,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'abbreviation' => 'SVD',
                'full_name' => 'Society of the Divine Word',
                'common_name' => 'Divine Word Missionaries',
                'type' => 'society',
                'branch' => 'male',
                'description' => 'Missionary congregation founded by St. Arnold Janssen',
                'founded_year' => 1875,
                'founder' => 'St. Arnold Janssen',
                'website' => 'https://www.svdcuria.org',
                'display_order' => 5,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'abbreviation' => 'CMI',
                'full_name' => 'Carmelites of Mary Immaculate',
                'common_name' => 'CMI Fathers',
                'type' => 'congregation',
                'branch' => 'male',
                'description' => 'First indigenous religious congregation for men in India',
                'founded_year' => 1831,
                'founder' => 'Blessed Kuriakose Elias Chavara',
                'website' => 'https://www.cmifathers.in',
                'display_order' => 6,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'abbreviation' => 'OCD',
                'full_name' => 'Order of Discalced Carmelites',
                'common_name' => 'Discalced Carmelites',
                'type' => 'order',
                'branch' => 'male',
                'description' => 'Reformed Carmelite order founded by St. Teresa of Avila and St. John of the Cross',
                'founded_year' => 1593,
                'founder' => 'St. Teresa of Avila',
                'website' => 'https://www.ocd.org',
                'display_order' => 7,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'abbreviation' => 'SDB',
                'full_name' => 'Salesians of Don Bosco',
                'common_name' => 'Salesians',
                'type' => 'society',
                'branch' => 'male',
                'description' => 'Founded by St. John Bosco for the education and care of youth',
                'founded_year' => 1859,
                'founder' => 'St. John Bosco',
                'website' => 'https://www.sdb.org',
                'display_order' => 8,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'abbreviation' => 'OSB',
                'full_name' => 'Order of Saint Benedict',
                'common_name' => 'Benedictines',
                'type' => 'order',
                'branch' => 'male',
                'description' => 'Ancient monastic order founded by St. Benedict of Nursia',
                'founded_year' => 529,
                'founder' => 'St. Benedict of Nursia',
                'website' => 'https://www.osb.org',
                'display_order' => 9,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'abbreviation' => 'CSC',
                'full_name' => 'Congregation of Holy Cross',
                'common_name' => 'Holy Cross Fathers',
                'type' => 'congregation',
                'branch' => 'male',
                'description' => 'Founded by Blessed Basile Moreau, known for education',
                'founded_year' => 1837,
                'founder' => 'Blessed Basile Moreau',
                'website' => 'https://www.holycrosscongregation.org',
                'display_order' => 10,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'abbreviation' => 'OP',
                'full_name' => 'Order of Preachers',
                'common_name' => 'Dominicans',
                'type' => 'order',
                'branch' => 'male',
                'description' => 'Founded by St. Dominic de Guzman for preaching and teaching',
                'founded_year' => 1216,
                'founder' => 'St. Dominic de Guzman',
                'website' => 'https://www.op.org',
                'display_order' => 11,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'abbreviation' => 'MCBS',
                'full_name' => 'Missionary Congregation of the Blessed Sacrament',
                'common_name' => 'MCBS Fathers',
                'type' => 'congregation',
                'branch' => 'male',
                'description' => 'Indian missionary congregation',
                'founded_year' => 1960,
                'founder' => 'Archbishop Thomas Pothacamury',
                'website' => null,
                'display_order' => 12,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            
            // Diocesan Clergy (not a religious order but needed for database completeness)
            [
                'abbreviation' => 'DIOC',
                'full_name' => 'Diocesan Clergy',
                'common_name' => 'Diocesan Priest',
                'type' => 'institute',
                'branch' => 'male',
                'description' => 'Priest ordained for and incardinated in a particular diocese',
                'founded_year' => null,
                'founder' => null,
                'website' => null,
                'display_order' => 99,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('religious_orders')->insert($orders);

        $this->command->info('✅ Religious orders seeded successfully!');
        $this->command->info('   Total: ' . count($orders) . ' orders/congregations');
    }
}

