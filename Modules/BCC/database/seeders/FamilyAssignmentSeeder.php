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

        if (! $tenant) {
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
            ->whereNull('bcc_id')
            ->get();

        if ($families->isEmpty()) {
            $this->command->error('No families found. Please run FamiliesSeeder first.');

            return;
        }

        $this->command->info('Assigning '.$families->count().' families to '.$bccs->count().' BCCs...');

        DB::beginTransaction();

        try {
            $assignedCount = 0;
            $bccList = $bccs->values();
            $familiesPerBcc = (int) ceil($families->count() / max($bccList->count(), 1));

            foreach ($bccList as $index => $bcc) {
                $assignedToBcc = 0;
                $start = $index * $familiesPerBcc;

                foreach ($families->slice($start, $familiesPerBcc) as $family) {
                    if ($family->bcc_id !== null) {
                        continue;
                    }

                    $family->update(['bcc_id' => $bcc->id]);
                    $assignedToBcc++;
                    $assignedCount++;
                }

                $this->command->info(sprintf('  ✓ %s: %d families assigned', $bcc->name, $assignedToBcc));
            }

            $unassigned = Family::where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->whereNull('bcc_id')
                ->count();

            DB::commit();

            $this->command->info('');
            $this->command->info("✅ Successfully assigned $assignedCount families to BCCs!");
            $this->command->info("   Unassigned families: $unassigned");

            $this->command->info('');
            $this->command->info('📊 BCC family counts:');

            $bccsWithCounts = BCC::where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->withCount('families')
                ->get();

            foreach ($bccsWithCounts as $bcc) {
                $this->command->info(sprintf(
                    '   • %s: %d families',
                    $bcc->name,
                    $bcc->families_count
                ));
            }
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('Failed to assign families: '.$e->getMessage());
            $this->command->error($e->getTraceAsString());
        }
    }
}
