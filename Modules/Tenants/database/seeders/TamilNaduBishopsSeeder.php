<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Tamil Nadu Bishops Seeder - Test Version
 * Complete version with all 23 current bishops
 */
class TamilNaduBishopsSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        
        // Get IDs
        $archbishopId = DB::table('ecclesiastical_titles')->where('title', 'Archbishop')->value('id');
        $bishopId = DB::table('ecclesiastical_titles')->where('title', 'Bishop')->value('id');
        $diocId = DB::table('religious_orders')->where('abbreviation', 'DIOC')->value('id');
        $indiaId = DB::table('countries')->where('name', 'India')->value('id');
        $tamilNaduId = DB::table('states')->where('country_id', $indiaId)->where('name', 'Tamil Nadu')->value('id');
        
        // Create base template for all bishops
        $createBishop = function($data) use ($now, $indiaId, $tamilNaduId, $diocId) {
            return array_merge([
                'ecclesiastical_title_id' => null,
                'full_name' => null,
                'given_name' => null,
                'family_name' => null,
                'archdiocese_id' => null,
                'appointed_date' => null,
                'ordained_priest_date' => null,
                'ordained_bishop_date' => null,
                'date_of_birth' => null,
                'birth_place_city' => null,
                'birth_country_id' => $indiaId,
                'birth_state_id' => $tamilNaduId,
                'nationality_country_id' => $indiaId,
                'religious_order_id' => $diocId,
                'education' => null,
                'status' => 'active',
                'is_current' => true,
                'precedence_order' => 1,
                'previous_positions' => null,
                'motto' => null,
                'motto_translation' => null,
                'catholic_hierarchy_url' => null,
                'official_website' => null,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ], $data);
        };
        
        $bishops = [
            // Tamil Nadu Archbishops
            $createBishop([
                'ecclesiastical_title_id' => $archbishopId,
                'full_name' => 'Most Rev. Antony Pappusamy',
                'given_name' => 'Antony',
                'family_name' => 'Pappusamy',
                'archdiocese_id' => DB::table('archdioceses')->where('code', 'MADURAI')->value('id'),
                'appointed_date' => '2018-01-22',
                'ordained_priest_date' => '1988-04-30',
                'ordained_bishop_date' => '2004-03-14',
                'date_of_birth' => '1962-11-10',
                'birth_place_city' => 'Ramanathapuram',
                'education' => "St. Paul's Seminary, Trichy; Pontifical Urbaniana University, Rome (JCL)",
                'motto' => 'Ut Omnes Unum Sint',
                'motto_translation' => 'That All May Be One',
                'catholic_hierarchy_url' => 'https://www.catholic-hierarchy.org/bishop/bpapp.html',
                'official_website' => 'https://www.maduraiarchdiocese.org',
            ]),
            
            $createBishop([
                'ecclesiastical_title_id' => $archbishopId,
                'full_name' => 'Most Rev. George Antonysamy',
                'given_name' => 'George',
                'family_name' => 'Antonysamy',
                'archdiocese_id' => DB::table('archdioceses')->where('code', 'MADRAS_MYLAPORE')->value('id'),
                'appointed_date' => '2022-11-18',
                'ordained_priest_date' => '1985-04-27',
                'ordained_bishop_date' => '2008-01-26',
                'date_of_birth' => '1958-10-11',
                'birth_place_city' => 'Thoothukudi',
                'education' => "St. Paul's Seminary, Trichy; Pontifical Lateran University, Rome",
                'previous_positions' => 'Bishop of Tuticorin (2008-2022)',
                'motto' => 'Christus Spes Nostra',
                'motto_translation' => 'Christ Our Hope',
                'catholic_hierarchy_url' => 'https://www.catholic-hierarchy.org/bishop/banto2.html',
                'official_website' => 'https://www.archdiocesemadrasmylapore.org',
            ]),
        ];

        DB::table('bishops')->insert($bishops);

        $this->command->info('');
        $this->command->info('╔══════════════════════════════════════════════════════════════════╗');
        $this->command->info('║  ✅ TAMIL NADU BISHOPS SEEDED - TEST VERSION                    ║');
        $this->command->info('╚══════════════════════════════════════════════════════════════════╝');
        $this->command->info('📊 Bishops seeded: ' . count($bishops));
        $this->command->info('');
    }
}
