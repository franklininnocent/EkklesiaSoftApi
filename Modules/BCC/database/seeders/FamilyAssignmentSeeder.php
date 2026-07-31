<?php

namespace Modules\BCC\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\BCC\Models\BCC;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\Tenant;

class FamilyAssignmentSeeder extends Seeder
{
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

        $bccs = BCC::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->get();
        
        if ($bccs->isEmpty()) {
            $this->command->error('No BCCs found. Please run BCCsSeeder first.');
            return;
        }

        $families = Family::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->whereNull('bcc_id') // Only unassigned families
            ->get();
        
        if ($families->isEmpty()) {
            $this->command->error('No families found. Please run FamiliesSeeder first.');
            return;
        }

        $this->command->info('Assigning ' . $families->count() . ' families to ' . $bccs->count() . ' BCCs...');

        DB::beginTransaction();
        
        try {
            $assignedCount = 0;
            $skippedCount = 0;

            // Group families by parish zone for better distribution
            $familiesByZone = $families->groupBy('parish_zone_id');

            foreach ($bccs as $bcc) {
                // Get families from the same zone first
                $zoneFamilies = $familiesByZone->get($bcc->parish_zone_id, collect());
                
                // If not enough families in the zone, get from other zones
                if ($zoneFamilies->isEmpty()) {
                    $zoneFamilies = $families->where('bcc_id', null);
                }

                // Calculate how many families to assign to this BCC
                // Aim for 60-80% capacity utilization
                $targetCount = (int) (($bcc->min_families + $bcc->max_families) / 2);
                $targetCount = min($targetCount, $zoneFamilies->count());

                if ($targetCount === 0) {
                    $this->command->info("  ⚠ {$bcc->name}: No families available");
                    continue;
                }

                // Assign families to this BCC
                $assignedToBcc = 0;
                foreach ($zoneFamilies->take($targetCount) as $family) {
                    if ($family->bcc_id === null) {
                        $family->update(['bcc_id' => $bcc->id]);
                        $assignedToBcc++;
                        $assignedCount++;
                    }
                }

                $capacityPercentage = ($assignedToBcc / $bcc->max_families) * 100;
                $this->command->info(sprintf(
                    "  ✓ %s: %d families assigned (%.0f%% capacity)",
                    $bcc->name,
                    $assignedToBcc,
                    $capacityPercentage
                ));
            }

            // Count remaining unassigned families
            $unassigned = Family::where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->whereNull('bcc_id')
                ->count();
            
            DB::commit();
            
            $this->command->info('');
            $this->command->info("✅ Successfully assigned $assignedCount families to BCCs!");
            $this->command->info("   Unassigned families: $unassigned");
            
            // Show BCC utilization summary
            $this->command->info('');
            $this->command->info('📊 BCC Capacity Utilization:');
            
            $bccsWithCounts = BCC::where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->withCount('families')
                ->get();
            
            foreach ($bccsWithCounts as $bcc) {
                $percentage = $bcc->families_count > 0 ? ($bcc->families_count / $bcc->max_families) * 100 : 0;
                $status = $percentage >= 90 ? '🔴' : ($percentage >= 70 ? '🟡' : '🟢');
                
                $this->command->info(sprintf(
                    "   %s %s: %d/%d families (%.0f%%)",
                    $status,
                    $bcc->name,
                    $bcc->families_count,
                    $bcc->max_families,
                    $percentage
                ));
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('Failed to assign families: ' . $e->getMessage());
            $this->command->error($e->getTraceAsString());
        }
    }
}


