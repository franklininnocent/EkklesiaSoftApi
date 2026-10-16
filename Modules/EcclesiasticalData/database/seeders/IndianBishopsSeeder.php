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
     * CSV diocese keys mapped to canonical archdiocese names from ComprehensiveArchdiocesesSeeder.
     *
     * @var array<string, string>
     */
    private const DIOCESE_ALIASES = [
        'madras-mylapore' => 'Archdiocese of Madras and Mylapore',
        'pondicherry-cuddalore' => 'Archdiocese of Pondicherry and Cuddalore',
        'thoothukudi' => 'Diocese of Tuticorin',
        'tuticorin' => 'Diocese of Tuticorin',
        'tiruchirapalli' => 'Diocese of Trichy',
        'tiruchirappalli' => 'Diocese of Trichy',
        'trichy' => 'Diocese of Trichy',
        'ooty' => 'Diocese of Ootacamund',
    ];

    /**
     * Legacy stub names created by earlier seeder versions before alias resolution existed.
     *
     * @var list<string>
     */
    private const LEGACY_STUB_DIOCESE_NAMES = [
        'Madras-Mylapore',
        'Pondicherry-Cuddalore',
        'Thoothukudi',
        'Tiruchirapalli',
    ];

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
                    $dioceseName = trim($data['diocese_name']);

                    if (! isset($diocesesCache[$dioceseName])) {
                        $diocese = $this->resolveArchdiocese($dioceseName, (int) $india->id);

                        if (! $diocese) {
                            $this->command->warn("Diocese not found: {$dioceseName}. Skipping row.");
                            $skippedCount++;
                            continue;
                        }

                        $diocesesCache[$dioceseName] = $diocese->id;
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

                    $statusFlags = $this->resolveStatusFlags(
                        ! empty($data['status']) ? (string) $data['status'] : 'active'
                    );

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
                        'status' => $statusFlags['status'],
                        'biography' => !empty($data['notes']) ? trim($data['notes']) : null,
                        'is_current' => $statusFlags['is_current'],
                        'active' => $statusFlags['active'],
                    ];

                    // Match by person identity so re-runs can correct archdiocese links.
                    $matches = Bishop::query()
                        ->where('given_name', $bishopData['given_name'])
                        ->where('family_name', $bishopData['family_name'])
                        ->where('nationality_country_id', $india->id)
                        ->orderBy('id')
                        ->get();

                    $existing = $matches->first();

                    if ($existing) {
                        $existing->update($bishopData);
                        $bishop = $existing->fresh();

                        if ($matches->count() > 1) {
                            Bishop::query()
                                ->whereIn('id', $matches->skip(1)->pluck('id'))
                                ->delete();
                        }

                        $updatedCount++;
                        $this->command->info("✓ Updated: {$bishopData['full_name']} - {$data['diocese_name']}");
                    } else {
                        $bishop = Bishop::create($bishopData);
                        $importedCount++;
                        $this->command->info("✓ Imported: {$bishopData['full_name']} - {$data['diocese_name']}");
                    }

                    if ($bishopData['is_current']) {
                        $this->demoteOtherCurrentOrdinaries(
                            (int) $bishopData['archdiocese_id'],
                            (int) $bishop->id
                        );
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

            $removedStubDioceses = $this->cleanupOrphanStubDioceses((int) $india->id);

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
            if ($removedStubDioceses > 0) {
                $this->command->info("🧹 Orphan stub dioceses removed: {$removedStubDioceses}");
            }
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

    private function resolveArchdiocese(string $csvDioceseName, int $countryId): ?Archdiocese
    {
        $key = $this->normalizeDioceseKey($csvDioceseName);

        if (isset(self::DIOCESE_ALIASES[$key])) {
            $diocese = Archdiocese::query()
                ->where('country_id', $countryId)
                ->where('name', self::DIOCESE_ALIASES[$key])
                ->first();

            if ($diocese) {
                return $diocese;
            }
        }

        $searchTerms = array_unique(array_filter([
            $csvDioceseName,
            "Diocese of {$csvDioceseName}",
            "Archdiocese of {$csvDioceseName}",
            str_contains($csvDioceseName, '-')
                ? 'Archdiocese of '.str_replace('-', ' and ', $csvDioceseName)
                : null,
            str_contains($csvDioceseName, '-')
                ? 'Diocese of '.str_replace('-', ' and ', $csvDioceseName)
                : null,
        ]));

        foreach ($searchTerms as $term) {
            $diocese = Archdiocese::query()
                ->where('country_id', $countryId)
                ->where(function ($query) use ($term) {
                    $query->where('name', 'ILIKE', $term)
                        ->orWhere('name', 'ILIKE', "%{$term}%")
                        ->orWhere('headquarters_city', 'ILIKE', $term);
                })
                ->first();

            if ($diocese) {
                return $diocese;
            }
        }

        $tokens = array_values(array_filter(
            preg_split('/[\s\-]+/', strtolower($csvDioceseName)) ?: [],
            static fn (string $token): bool => strlen($token) > 2
        ));

        if ($tokens !== []) {
            $query = Archdiocese::query()->where('country_id', $countryId);

            foreach ($tokens as $token) {
                $query->where('name', 'ILIKE', "%{$token}%");
            }

            $diocese = $query->first();

            if ($diocese) {
                return $diocese;
            }
        }

        return null;
    }

    private function normalizeDioceseKey(string $name): string
    {
        return strtolower(preg_replace('/[\s\-]+/', '-', trim($name)) ?? trim($name));
    }

    private function cleanupOrphanStubDioceses(int $countryId): int
    {
        return Archdiocese::query()
            ->where('country_id', $countryId)
            ->whereIn('name', self::LEGACY_STUB_DIOCESE_NAMES)
            ->whereDoesntHave('bishops')
            ->delete();
    }

    /**
     * @return array{status: string, is_current: bool, active: int}
     */
    private function resolveStatusFlags(string $status): array
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'active' => [
                'status' => 'active',
                'is_current' => true,
                'active' => 1,
            ],
            'retired', 'emeritus' => [
                'status' => $status === 'emeritus' ? 'emeritus' : 'retired',
                'is_current' => false,
                'active' => 1,
            ],
            'deceased' => [
                'status' => 'deceased',
                'is_current' => false,
                'active' => 0,
            ],
            default => [
                'status' => 'active',
                'is_current' => true,
                'active' => 1,
            ],
        };
    }

    private function demoteOtherCurrentOrdinaries(int $archdioceseId, int $bishopId): void
    {
        Bishop::query()
            ->where('archdiocese_id', $archdioceseId)
            ->where('is_current', true)
            ->where('id', '!=', $bishopId)
            ->update(['is_current' => false]);
    }
}

