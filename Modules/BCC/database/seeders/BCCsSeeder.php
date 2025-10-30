<?php

namespace Modules\BCC\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\BCC\Models\BCC;
use Modules\BCC\Models\BCCLeader;
use Modules\Family\Models\ParishZone;
use Modules\Family\Models\FamilyMember;
use Modules\Tenants\Models\Tenant;

class BCCsSeeder extends Seeder
{
    private array $bccNames = [
        'Faith Community', 'Hope Community', 'Love Community', 'Peace Community', 'Grace Community',
        'Light Community', 'Unity Community', 'Spirit Community', 'Joy Community', 'Truth Community',
        'Life Community', 'Wisdom Community', 'Mercy Community', 'Blessing Community', 'Covenant Community'
    ];

    private array $meetingDays = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    private array $meetingFrequencies = ['Weekly', 'Bi-weekly', 'Monthly', 'Twice a month'];
    private array $leaderRoles = ['Coordinator', 'Assistant Coordinator', 'Secretary', 'Treasurer', 'Facilitator'];

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

        $familyMembers = FamilyMember::whereHas('family', function ($query) use ($tenant) {
            $query->where('tenant_id', $tenant->id);
        })->where('status', 'active')->get();

        if ($familyMembers->isEmpty()) {
            $this->command->error('No family members found. Please run FamiliesSeeder first.');
            return;
        }

        $this->command->info('Creating 12 BCCs with leaders for tenant: ' . $tenant->name);

        DB::beginTransaction();
        
        try {
            $bccCount = 0;
            $leaderCount = 0;

            foreach ($zones->take(12) as $index => $zone) {
                $bccName = $this->bccNames[$index % count($this->bccNames)];
                $meetingDay = $this->meetingDays[array_rand($this->meetingDays)];
                $meetingTime = sprintf('%02d:00', rand(14, 19)); // 2 PM to 7 PM
                $minFamilies = rand(10, 15);
                $maxFamilies = rand(40, 50);
                
                // Create BCC
                $bcc = BCC::create([
                    'tenant_id' => $tenant->id,
                    'bcc_code' => sprintf('BCC%04d', $index + 1),
                    'name' => $bccName . ' - ' . $zone->name,
                    'description' => "A vibrant {$bccName} serving families in the {$zone->name} area. We meet regularly for prayer, fellowship, and community building activities.",
                    'parish_zone_id' => $zone->id,
                    'meeting_place' => ['Church Hall', 'Community Center', 'Parish House', 'Member\'s Home'][array_rand(['Church Hall', 'Community Center', 'Parish House', 'Member\'s Home'])],
                    'meeting_day' => $meetingDay,
                    'meeting_time' => $meetingTime,
                    'meeting_frequency' => $this->meetingFrequencies[array_rand($this->meetingFrequencies)],
                    'min_families' => $minFamilies,
                    'max_families' => $maxFamilies,
                    'contact_phone' => sprintf('+1-555-%04d', rand(2000, 2999)),
                    'contact_email' => strtolower(str_replace(' ', '', $bccName)) . '@parish.org',
                    'status' => rand(1, 100) > 10 ? 'active' : 'inactive', // 90% active
                    'established_date' => now()->subYears(rand(1, 10))->subMonths(rand(1, 11))->format('Y-m-d'),
                    'notes' => 'Regular meetings focus on scripture sharing, community support, and social activities.',
                    'created_by' => 1,
                    'updated_by' => 1,
                ]);
                
                $bccCount++;

                // Assign 1-2 leaders to each BCC
                $numLeaders = rand(1, 2);
                $availableMembers = $familyMembers->random(min($numLeaders, $familyMembers->count()));
                
                foreach ($availableMembers as $leaderIndex => $member) {
                    $role = $leaderIndex === 0 ? 'coordinator' : ['assistant', 'secretary', 'treasurer'][array_rand(['assistant', 'secretary', 'treasurer'])];
                    $appointedDate = $bcc->established_date;
                    
                    BCCLeader::create([
                        'bcc_id' => $bcc->id,
                        'family_member_id' => $member->id,
                        'role' => $role,
                        'role_description' => ucfirst($role) . ' of ' . $bcc->name,
                        'appointed_date' => $appointedDate,
                        'term_start_date' => $appointedDate,
                        'is_active' => true,
                        'responsibilities' => $this->getResponsibilities($role),
                        'created_by' => 1,
                        'updated_by' => 1,
                    ]);
                    
                    $leaderCount++;
                }

                $this->command->info("✓ Created BCC: {$bcc->name} with {$numLeaders} leader(s)");
            }
            
            DB::commit();
            
            $this->command->info('');
            $this->command->info("✅ Successfully created $bccCount BCCs with $leaderCount total leaders!");
            $this->command->info("   Average: " . number_format($leaderCount / $bccCount, 1) . " leaders per BCC");
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('Failed to create BCCs: ' . $e->getMessage());
            $this->command->error($e->getTraceAsString());
        }
    }

    /**
     * Get responsibilities based on role
     */
    private function getResponsibilities(string $role): string
    {
        $responsibilities = [
            'coordinator' => 'Overall leadership of BCC activities, coordinating meetings, maintaining communication with parish, organizing community events',
            'assistant' => 'Supporting the coordinator, assisting with meeting preparation, backup leadership, member outreach',
            'secretary' => 'Recording meeting minutes, managing attendance, maintaining member records, handling correspondence',
            'treasurer' => 'Managing BCC finances, collecting contributions, maintaining financial records, preparing budget reports',
            'leader' => 'Primary leadership and guidance of BCC members',
            'animator' => 'Leading prayer sessions, facilitating discussions, organizing study groups, spiritual guidance'
        ];
        
        return $responsibilities[$role] ?? 'Supporting BCC activities and community building initiatives';
    }
}

