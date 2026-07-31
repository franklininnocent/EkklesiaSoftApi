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

        // Step 1: Parish Zones
        $this->command->info('📍 Step 1/4: Creating Parish Zones...');
        $this->command->info('─────────────────────────────────────────────');
        $this->call(ParishZonesSeeder::class);
        $this->command->info('');

        // Step 2: Families with Members
        $this->command->info('👨‍👩‍👧‍👦 Step 2/4: Creating Families with Members...');
        $this->command->info('─────────────────────────────────────────────');
        $this->call(FamiliesSeeder::class);
        $this->command->info('');

        // Step 3: BCCs with Leaders
        $this->command->info('🏘️  Step 3/4: Creating BCCs with Leaders...');
        $this->command->info('─────────────────────────────────────────────');
        $this->call(\Modules\BCC\Database\Seeders\BCCsSeeder::class);
        $this->command->info('');

        // Step 4: Assign Families to BCCs
        $this->command->info('🔗 Step 4/4: Assigning Families to BCCs...');
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
        $this->command->info('║   • 12 Parish Zones                                                ║');
        $this->command->info('║   • 60 Families with ~200 Members                                  ║');
        $this->command->info('║   • 12 BCCs with Leaders                                           ║');
        $this->command->info('║   • Family-to-BCC Assignments                                      ║');
        $this->command->info('║                                                                    ║');
        $this->command->info('║   🚀 Ready to test all 25 API endpoints!                           ║');
        $this->command->info('║                                                                    ║');
        $this->command->info('╚════════════════════════════════════════════════════════════════════╝');
        $this->command->info('');
    }
}


