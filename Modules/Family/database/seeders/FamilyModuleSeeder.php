<?php

namespace Modules\Family\Database\Seeders;

use Illuminate\Database\Seeder;

class FamilyModuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('');
        $this->command->info('╔════════════════════════════════════════════════════════════════════╗');
        $this->command->info('║                                                                    ║');
        $this->command->info('║         FAMILY & BCC MODULES - COMPREHENSIVE SEEDING               ║');
        $this->command->info('║                                                                    ║');
        $this->command->info('╚════════════════════════════════════════════════════════════════════╝');
        $this->command->info('');

        $this->command->info('🌱 Starting comprehensive seeding process...');
        $this->command->info('');

        // Step 1: Families with Members
        $this->command->info('👨‍👩‍👧‍👦 Step 1/3: Creating Families with Members...');
        $this->command->info('─────────────────────────────────────────────');
        $this->call(FamiliesSeeder::class);
        $this->command->info('');

        // Step 2: BCCs with Leaders
        $this->command->info('🏘️  Step 2/3: Creating BCCs with Leaders...');
        $this->command->info('─────────────────────────────────────────────');
        $this->call(\Modules\BCC\Database\Seeders\BCCsSeeder::class);
        $this->command->info('');

        // Step 3: Assign Families to BCCs
        $this->command->info('🔗 Step 3/3: Assigning Families to BCCs...');
        $this->command->info('─────────────────────────────────────────────');
        $this->call(\Modules\BCC\Database\Seeders\FamilyAssignmentSeeder::class);
        $this->command->info('');

        // Summary
        $this->command->info('');
        $this->command->info('╔════════════════════════════════════════════════════════════════════╗');
        $this->command->info('║                                                                    ║');
        $this->command->info('║         ✅ SEEDING COMPLETE!                                        ║');
        $this->command->info('║                                                                    ║');
        $this->command->info('║   Created:                                                         ║');
        $this->command->info('║   • Families with Members                                          ║');
        $this->command->info('║   • BCCs with Leaders                                              ║');
        $this->command->info('║   • Family-to-BCC Assignments                                      ║');
        $this->command->info('║                                                                    ║');
        $this->command->info('║   🚀 Ready to test all 25 API endpoints!                           ║');
        $this->command->info('║                                                                    ║');
        $this->command->info('╚════════════════════════════════════════════════════════════════════╝');
        $this->command->info('');
    }
}


