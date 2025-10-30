<?php

namespace Modules\EcclesiasticalData\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Tenants\Models\Archdiocese;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\State;
use Modules\EcclesiasticalData\Models\EcclesiasticalTitle;
use Modules\EcclesiasticalData\Models\ReligiousOrder;
use Modules\Tenants\Models\Bishop;

class IndianBishopsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🇮🇳 Starting Indian Bishops Data Import...');

        // Path to CSV file
        $csvFile = __DIR__ . '/data/bishops_india.csv';

        if (!file_exists($csvFile)) {
            $this->command->error("CSV file not found at: {$csvFile}");
            return;
        }

        // Get India country ID
        $india = Country::where('name', 'India')->first();
        if (!$india) {
            $this->command->error('India not found in countries table. Please seed countries first.');
            return;
        }

        $this->command->info("India Country ID: {$india->id}");

        // Cache for lookups
        $diocesesCache = [];
        $titlesCache = [];
        $statesCache = [];
        $importedCount = 0;
        $skippedCount = 0;
        $updatedCount = 0;
        $errors = [];

        // Open and read CSV
        $handle = fopen($csvFile, 'r');
        $header = fgetcsv($handle); // Skip header row

        $this->command->info('CSV Headers: ' . implode(', ', $header));

        DB::beginTransaction();

        try {
            while (($row = fgetcsv($handle)) !== false) {
                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }

                // Map CSV columns
                $data = array_combine($header, $row);

                // Validate required fields
                if (empty($data['diocese_name']) || empty($data['last_name'])) {
                    $this->command->warn("Skipping row: Missing diocese_name or last_name");
                    $skippedCount++;
                    continue;
                }

                try {
                    // Find or create diocese
                    if (!isset($diocesesCache[$data['diocese_name']])) {
                        $diocese = Archdiocese::where('name', 'LIKE', '%' . trim($data['diocese_name']) . '%')->first();
                        
                        if (!$diocese) {
                            // Create diocese if not exists
                            $this->command->warn("Diocese not found: {$data['diocese_name']}. Creating...");
                            $diocese = Archdiocese::create([
                                'name' => trim($data['diocese_name']),
                                'country_id' => $india->id,
                                'active' => true,
                            ]);
                        }
                        
                        $diocesesCache[$data['diocese_name']] = $diocese->id;
                    }

                    // Find ecclesiastical title
                    $titleName = trim($data['ecclesiastical_title'] ?: $data['bishop_title']);
                    if (!isset($titlesCache[$titleName])) {
                        $title = EcclesiasticalTitle::where('title', 'LIKE', '%' . $titleName . '%')->first();
                        
                        if (!$title) {
                            // Create title if not exists
                            $this->command->warn("Title not found: {$titleName}. Using default 'Bishop'");
                            $title = EcclesiasticalTitle::where('title', 'Bishop')->first();
                        }
                        
                        $titlesCache[$titleName] = $title ? $title->id : null;
                    }

                    // Find state from place of birth
                    $stateId = null;
                    if (!empty($data['place_of_birth']) && strpos($data['place_of_birth'], ',') !== false) {
                        $parts = explode(',', $data['place_of_birth']);
                        $stateName = trim(end($parts));
                        
                        if (!isset($statesCache[$stateName])) {
                            $state = State::where('country_id', $india->id)
                                ->where('name', 'LIKE', '%' . $stateName . '%')
                                ->first();
                            $statesCache[$stateName] = $state ? $state->id : null;
                        }
                        
                        $stateId = $statesCache[$stateName];
                    }

                    // Build full name
                    $givenName = trim($data['first_name'] . ' ' . ($data['middle_name'] ?? ''));
                    $familyName = trim($data['last_name']);
                    $fullName = trim($givenName . ' ' . $familyName);

                    // Prepare bishop data using correct column names
                    $bishopData = [
                        'archdiocese_id' => $diocesesCache[$data['diocese_name']],
                        'ecclesiastical_title_id' => $titlesCache[$titleName],
                        'given_name' => $givenName,
                        'family_name' => $familyName,
                        'full_name' => $fullName,
                        'date_of_birth' => !empty($data['date_of_birth']) ? $data['date_of_birth'] : null,
                        'birth_place_city' => !empty($data['place_of_birth']) ? trim($data['place_of_birth']) : null,
                        'birth_country_id' => $india->id,
                        'birth_state_id' => $stateId,
                        'nationality_country_id' => $india->id,
                        'ordained_priest_date' => !empty($data['date_of_ordination']) ? $data['date_of_ordination'] : null,
                        'ordained_bishop_date' => !empty($data['date_of_episcopal_ordination']) ? $data['date_of_episcopal_ordination'] : null,
                        'appointed_date' => !empty($data['date_of_appointment']) ? $data['date_of_appointment'] : null,
                        'email' => !empty($data['email']) ? trim($data['email']) : null,
                        'phone' => !empty($data['phone']) ? trim($data['phone']) : null,
                        'status' => !empty($data['status']) ? strtolower(trim($data['status'])) : 'active',
                        'biography' => !empty($data['notes']) ? trim($data['notes']) : null,
                        'is_current' => true,
                        'active' => true,
                    ];

                    // Check if bishop already exists
                    $existing = Bishop::where('given_name', $bishopData['given_name'])
                        ->where('family_name', $bishopData['family_name'])
                        ->where('archdiocese_id', $bishopData['archdiocese_id'])
                        ->first();

                    if ($existing) {
                        // Update existing record
                        $existing->update($bishopData);
                        $updatedCount++;
                        $this->command->info("✓ Updated: {$bishopData['full_name']} - {$data['diocese_name']}");
                    } else {
                        // Create new record
                        Bishop::create($bishopData);
                        $importedCount++;
                        $this->command->info("✓ Imported: {$bishopData['full_name']} - {$data['diocese_name']}");
                    }

                } catch (\Exception $e) {
                    $error = "Error processing {$data['first_name']} {$data['last_name']}: " . $e->getMessage();
                    $this->command->error($error);
                    $errors[] = $error;
                    $skippedCount++;
                    continue;
                }
            }

            fclose($handle);

            DB::commit();

            // Summary
            $this->command->info('');
            $this->command->info('═══════════════════════════════════════');
            $this->command->info('📊 IMPORT SUMMARY');
            $this->command->info('═══════════════════════════════════════');
            $this->command->info("✅ New Bishops Imported: {$importedCount}");
            $this->command->info("🔄 Bishops Updated: {$updatedCount}");
            $this->command->info("⏭️  Rows Skipped: {$skippedCount}");
            $this->command->info("❌ Errors: " . count($errors));
            $this->command->info('═══════════════════════════════════════');

            if (!empty($errors)) {
                $this->command->error('');
                $this->command->error('Errors encountered:');
                foreach ($errors as $error) {
                    $this->command->error("  - {$error}");
                }
            }

        } catch (\Exception $e) {
            fclose($handle);
            DB::rollBack();
            $this->command->error('Fatal error during import: ' . $e->getMessage());
            $this->command->error($e->getTraceAsString());
        }
    }
}

