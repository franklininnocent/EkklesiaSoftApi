<?php

namespace Modules\Family\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\ParishZone;
use Modules\Tenants\Models\Tenant;

class FamiliesSeeder extends Seeder
{
    private array $firstNames = [
        'male' => ['James', 'John', 'Robert', 'Michael', 'William', 'David', 'Richard', 'Joseph', 'Thomas', 'Christopher', 
                   'Daniel', 'Matthew', 'Anthony', 'Mark', 'Donald', 'Steven', 'Andrew', 'Paul', 'Joshua', 'Kenneth'],
        'female' => ['Mary', 'Patricia', 'Jennifer', 'Linda', 'Elizabeth', 'Barbara', 'Susan', 'Jessica', 'Sarah', 'Karen',
                     'Nancy', 'Lisa', 'Betty', 'Margaret', 'Sandra', 'Ashley', 'Kimberly', 'Emily', 'Donna', 'Michelle']
    ];
    
    private array $lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez',
                                 'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin',
                                 'Lee', 'Perez', 'Thompson', 'White', 'Harris', 'Sanchez', 'Clark', 'Ramirez', 'Lewis', 'Robinson'];
    
    private array $streets = ['Main', 'Oak', 'Maple', 'Cedar', 'Elm', 'Pine', 'Washington', 'Lake', 'Hill', 'Park',
                              'Church', 'River', 'Forest', 'Spring', 'Valley', 'Grove', 'Meadow', 'Sunset', 'Garden', 'Highland'];
    
    private array $cities = ['Springfield', 'Riverside', 'Greenfield', 'Madison', 'Franklin', 'Clinton', 'Arlington',
                             'Georgetown', 'Salem', 'Fairview', 'Bristol', 'Oxford', 'Milton', 'Newport', 'Auburn'];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenant = Tenant::first();
        
        if (!$tenant) {
            $this->command->error('No tenant found. Please create a tenant first.');
            return;
        }

        $zones = ParishZone::where('tenant_id', $tenant->id)->get();
        
        if ($zones->isEmpty()) {
            $this->command->error('No parish zones found. Please run ParishZonesSeeder first.');
            return;
        }

        $this->command->info('Creating 60 families with members for tenant: ' . $tenant->name);

        DB::beginTransaction();
        
        try {
            $familyCount = 0;
            $memberCount = 0;

            for ($i = 1; $i <= 60; $i++) {
                $lastName = $this->lastNames[array_rand($this->lastNames)];
                $zone = $zones->random();
                
                // Create family
                $family = Family::create([
                    'tenant_id' => $tenant->id,
                    'family_code' => sprintf('FAM%04d', $i),
                    'family_name' => $lastName . ' Family',
                    'head_of_family' => $this->firstNames['male'][array_rand($this->firstNames['male'])] . ' ' . $lastName,
                    'address_line_1' => rand(100, 9999) . ' ' . $this->streets[array_rand($this->streets)] . ' ' . ['St', 'Ave', 'Blvd', 'Dr', 'Ln'][array_rand(['St', 'Ave', 'Blvd', 'Dr', 'Ln'])],
                    'city' => $this->cities[array_rand($this->cities)],
                    'postal_code' => sprintf('%05d', rand(10000, 99999)),
                    'parish_zone_id' => $zone->id,
                    'primary_phone' => sprintf('+1-555-%04d', rand(1000, 9999)),
                    'email' => strtolower($lastName) . $i . '@email.com',
                    'status' => rand(1, 100) > 10 ? 'active' : 'inactive', // 90% active
                    'created_by' => 1,
                    'updated_by' => 1,
                ]);
                
                $familyCount++;

                // Create family members (2-5 members per family)
                $numMembers = rand(2, 5);
                
                // Head of family (male)
                $headFirstName = explode(' ', $family->head_of_family)[0];
                $headAge = rand(30, 60);
                $headBirthYear = now()->year - $headAge;
                
                $head = FamilyMember::create([
                    'family_id' => $family->id,
                    'first_name' => $headFirstName,
                    'last_name' => $lastName,
                    'date_of_birth' => "{$headBirthYear}-" . sprintf('%02d', rand(1, 12)) . "-" . sprintf('%02d', rand(1, 28)),
                    'gender' => 'male',
                    'relationship_to_head' => 'self',
                    'marital_status' => 'married',
                    'phone' => $family->primary_phone,
                    'email' => $family->email,
                    'is_primary_contact' => true,
                    'baptism_date' => ($headBirthYear + rand(0, 1)) . "-" . sprintf('%02d', rand(1, 12)) . "-" . sprintf('%02d', rand(1, 28)),
                    'baptism_place' => $zone->name . ' Church',
                    'first_communion_date' => ($headBirthYear + rand(7, 10)) . "-" . sprintf('%02d', rand(1, 12)) . "-" . sprintf('%02d', rand(1, 28)),
                    'confirmation_date' => ($headBirthYear + rand(12, 16)) . "-" . sprintf('%02d', rand(1, 12)) . "-" . sprintf('%02d', rand(1, 28)),
                    'occupation' => ['Engineer', 'Teacher', 'Manager', 'Accountant', 'Sales Representative', 'Technician'][array_rand(['Engineer', 'Teacher', 'Manager', 'Accountant', 'Sales Representative', 'Technician'])],
                    'status' => 'active',
                    'created_by' => 1,
                    'updated_by' => 1,
                ]);
                
                $memberCount++;

                // Spouse (female)
                if ($numMembers >= 2) {
                    $spouseAge = $headAge + rand(-5, 3);
                    $spouseBirthYear = now()->year - $spouseAge;
                    
                    FamilyMember::create([
                        'family_id' => $family->id,
                        'first_name' => $this->firstNames['female'][array_rand($this->firstNames['female'])],
                        'last_name' => $lastName,
                        'date_of_birth' => "{$spouseBirthYear}-" . sprintf('%02d', rand(1, 12)) . "-" . sprintf('%02d', rand(1, 28)),
                        'gender' => 'female',
                        'relationship_to_head' => 'spouse',
                        'marital_status' => 'married',
                        'phone' => sprintf('+1-555-%04d', rand(1000, 9999)),
                        'baptism_date' => ($spouseBirthYear + rand(0, 1)) . "-" . sprintf('%02d', rand(1, 12)) . "-" . sprintf('%02d', rand(1, 28)),
                        'baptism_place' => $zone->name . ' Church',
                        'first_communion_date' => ($spouseBirthYear + rand(7, 10)) . "-" . sprintf('%02d', rand(1, 12)) . "-" . sprintf('%02d', rand(1, 28)),
                        'confirmation_date' => ($spouseBirthYear + rand(12, 16)) . "-" . sprintf('%02d', rand(1, 12)) . "-" . sprintf('%02d', rand(1, 28)),
                        'marriage_date' => ($headBirthYear + rand(22, 28)) . "-" . sprintf('%02d', rand(1, 12)) . "-" . sprintf('%02d', rand(1, 28)),
                        'marriage_place' => $zone->name . ' Church',
                        'marriage_spouse_name' => $headFirstName . ' ' . $lastName,
                        'occupation' => ['Nurse', 'Teacher', 'Accountant', 'Manager', 'Designer', 'Consultant'][array_rand(['Nurse', 'Teacher', 'Accountant', 'Manager', 'Designer', 'Consultant'])],
                        'status' => 'active',
                        'created_by' => 1,
                        'updated_by' => 1,
                    ]);
                    
                    $memberCount++;
                }

                // Children
                for ($j = 3; $j <= $numMembers; $j++) {
                    $childAge = rand(5, 25);
                    $childBirthYear = now()->year - $childAge;
                    $childGender = rand(0, 1) ? 'male' : 'female';
                    $childRelationship = $childGender === 'male' ? 'son' : 'daughter';
                    
                    $memberData = [
                        'family_id' => $family->id,
                        'first_name' => $this->firstNames[$childGender][array_rand($this->firstNames[$childGender])],
                        'last_name' => $lastName,
                        'date_of_birth' => "{$childBirthYear}-" . sprintf('%02d', rand(1, 12)) . "-" . sprintf('%02d', rand(1, 28)),
                        'gender' => $childGender,
                        'relationship_to_head' => $childRelationship,
                        'marital_status' => $childAge >= 18 ? ['single', 'married'][array_rand(['single', 'married'])] : 'single',
                        'baptism_date' => ($childBirthYear + rand(0, 2)) . "-" . sprintf('%02d', rand(1, 12)) . "-" . sprintf('%02d', rand(1, 28)),
                        'baptism_place' => $zone->name . ' Church',
                        'status' => 'active',
                        'created_by' => 1,
                        'updated_by' => 1,
                    ];
                    
                    if ($childAge >= 7) {
                        $memberData['first_communion_date'] = ($childBirthYear + rand(7, 10)) . "-" . sprintf('%02d', rand(1, 12)) . "-" . sprintf('%02d', rand(1, 28));
                        $memberData['first_communion_place'] = $zone->name . ' Church';
                    }
                    
                    if ($childAge >= 14) {
                        $memberData['confirmation_date'] = ($childBirthYear + rand(12, 16)) . "-" . sprintf('%02d', rand(1, 12)) . "-" . sprintf('%02d', rand(1, 28));
                        $memberData['confirmation_place'] = $zone->name . ' Church';
                    }
                    
                    if ($childAge >= 18) {
                        $memberData['education'] = ['High School', 'Bachelor\'s Degree', 'Associate Degree', 'Studying'][array_rand(['High School', 'Bachelor\'s Degree', 'Associate Degree', 'Studying'])];
                    } else {
                        $memberData['education'] = $childAge >= 5 ? ['Elementary', 'Middle School', 'High School'][min(2, floor(($childAge - 5) / 4))] : 'Preschool';
                    }
                    
                    FamilyMember::create($memberData);
                    $memberCount++;
                }

                if ($i % 10 == 0) {
                    $this->command->info("✓ Created $i families with members...");
                }
            }
            
            DB::commit();
            
            $this->command->info('');
            $this->command->info("✅ Successfully created $familyCount families with $memberCount total members!");
            $this->command->info("   Average: " . number_format($memberCount / $familyCount, 1) . " members per family");
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('Failed to create families: ' . $e->getMessage());
            $this->command->error($e->getTraceAsString());
        }
    }
}


