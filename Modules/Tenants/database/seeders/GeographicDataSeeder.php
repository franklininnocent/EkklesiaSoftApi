<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class GeographicDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * 
     * Seeds countries and states data from JSON files.
     * This may take several minutes depending on data size.
     */
    public function run(): void
    {
        $this->command->info('🌍 Starting geographic data seeding...');
        
        // Seed countries
        $this->seedCountries();
        
        // Seed states
        $this->seedStates();
        
        $this->command->info('✅ Geographic data seeding completed successfully!');
    }
    
    /**
     * Seed countries from JSON file
     */
    private function seedCountries(): void
    {
        $this->command->info('📍 Seeding countries...');
        
        $jsonPath = base_path('countries-states-cities-database-master/json/countries.json');
        
        if (!File::exists($jsonPath)) {
            $this->command->error("Countries JSON file not found at: {$jsonPath}");
            return;
        }
        
        $json = File::get($jsonPath);
        $countries = json_decode($json, true);
        
        if (!is_array($countries)) {
            $this->command->error('Failed to decode countries JSON');
            return;
        }
        
        $this->command->info("Found " . count($countries) . " countries to import");
        
        $progressBar = $this->command->getOutput()->createProgressBar(count($countries));
        $progressBar->start();
        
        $insertData = [];
        $batchSize = 100;
        
        foreach ($countries as $country) {
            $insertData[] = [
                'id' => $country['id'],
                'name' => $country['name'],
                'iso3' => $country['iso3'],
                'iso2' => $country['iso2'],
                'numeric_code' => $country['numeric_code'] ?? null,
                'phone_code' => $country['phonecode'] ?? null,
                'capital' => $country['capital'] ?? null,
                'currency' => $country['currency'] ?? null,
                'currency_name' => $country['currency_name'] ?? null,
                'currency_symbol' => $country['currency_symbol'] ?? null,
                'tld' => $country['tld'] ?? null,
                'native' => $country['native'] ?? null,
                'latitude' => $country['latitude'] ?? null,
                'longitude' => $country['longitude'] ?? null,
                'region' => $country['region'] ?? null,
                'subregion' => $country['subregion'] ?? null,
                'emoji' => $country['emoji'] ?? null,
                'emoji_u' => $country['emojiU'] ?? null,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            
            // Insert in batches for better performance
            if (count($insertData) >= $batchSize) {
                DB::table('countries')->insert($insertData);
                $insertData = [];
            }
            
            $progressBar->advance();
        }
        
        // Insert remaining records
        if (!empty($insertData)) {
            DB::table('countries')->insert($insertData);
        }
        
        $progressBar->finish();
        $this->command->newLine();
        
        $count = DB::table('countries')->count();
        $this->command->info("✅ Successfully seeded {$count} countries");
    }
    
    /**
     * Seed states from JSON file
     */
    private function seedStates(): void
    {
        $this->command->info('📍 Seeding states/provinces...');
        
        $jsonPath = base_path('countries-states-cities-database-master/json/states.json');
        
        if (!File::exists($jsonPath)) {
            $this->command->error("States JSON file not found at: {$jsonPath}");
            return;
        }
        
        $json = File::get($jsonPath);
        $states = json_decode($json, true);
        
        if (!is_array($states)) {
            $this->command->error('Failed to decode states JSON');
            return;
        }
        
        $this->command->info("Found " . count($states) . " states/provinces to import");
        
        $progressBar = $this->command->getOutput()->createProgressBar(count($states));
        $progressBar->start();
        
        $insertData = [];
        $batchSize = 500;
        
        foreach ($states as $state) {
            $insertData[] = [
                'id' => $state['id'],
                'country_id' => $state['country_id'],
                'name' => $state['name'],
                'state_code' => $state['state_code'] ?? null,
                'type' => $state['type'] ?? null,
                'latitude' => $state['latitude'] ?? null,
                'longitude' => $state['longitude'] ?? null,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            
            // Insert in batches for better performance
            if (count($insertData) >= $batchSize) {
                DB::table('states')->insert($insertData);
                $insertData = [];
            }
            
            $progressBar->advance();
        }
        
        // Insert remaining records
        if (!empty($insertData)) {
            DB::table('states')->insert($insertData);
        }
        
        $progressBar->finish();
        $this->command->newLine();
        
        $count = DB::table('states')->count();
        $this->command->info("✅ Successfully seeded {$count} states/provinces");
    }
}


