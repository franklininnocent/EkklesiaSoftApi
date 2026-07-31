<?php

namespace Modules\Family\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Family\Models\ParishZone;
use Modules\Tenants\Models\Tenant;

class ParishZonesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get the first tenant (or create one for testing)
        $tenant = Tenant::first();
        
        if (!$tenant) {
            $this->command->error('No tenant found. Please create a tenant first.');
            return;
        }

        $this->command->info('Creating parish zones for tenant: ' . $tenant->name);

        $zones = [
            [
                'zone_code' => 'ZONE001',
                'name' => 'St. Mary Zone',
                'description' => 'Central parish zone covering downtown area',
                'area' => 'Downtown Central',
                'boundaries' => 'Main Street to 5th Avenue, bounded by River Road and Park Street',
                'coordinator_name' => 'John Smith',
                'coordinator_phone' => '+1-555-0101',
                'coordinator_email' => 'john.smith@parish.org',
                'active' => true,
                'display_order' => 1,
            ],
            [
                'zone_code' => 'ZONE002',
                'name' => 'Sacred Heart Zone',
                'description' => 'Northern residential zone',
                'area' => 'North District',
                'boundaries' => 'Park Street to North Highway, bounded by 1st to 10th Avenues',
                'coordinator_name' => 'Maria Garcia',
                'coordinator_phone' => '+1-555-0102',
                'coordinator_email' => 'maria.garcia@parish.org',
                'active' => true,
                'display_order' => 2,
            ],
            [
                'zone_code' => 'ZONE003',
                'name' => 'Holy Spirit Zone',
                'description' => 'Eastern suburban zone',
                'area' => 'East Suburbs',
                'boundaries' => 'East Highway to City Limits, bounded by Oak Road',
                'coordinator_name' => 'James Wilson',
                'coordinator_phone' => '+1-555-0103',
                'coordinator_email' => 'james.wilson@parish.org',
                'active' => true,
                'display_order' => 3,
            ],
            [
                'zone_code' => 'ZONE004',
                'name' => 'St. Joseph Zone',
                'description' => 'Western residential zone',
                'area' => 'West District',
                'boundaries' => 'West Boulevard to River Road, bounded by 15th to 25th Streets',
                'coordinator_name' => 'Sarah Johnson',
                'coordinator_phone' => '+1-555-0104',
                'coordinator_email' => 'sarah.johnson@parish.org',
                'active' => true,
                'display_order' => 4,
            ],
            [
                'zone_code' => 'ZONE005',
                'name' => 'Our Lady of Grace Zone',
                'description' => 'Southern zone with mixed residential and commercial',
                'area' => 'South District',
                'boundaries' => 'South Highway to Industrial Park, bounded by Railroad',
                'coordinator_name' => 'Michael Brown',
                'coordinator_phone' => '+1-555-0105',
                'coordinator_email' => 'michael.brown@parish.org',
                'active' => true,
                'display_order' => 5,
            ],
            [
                'zone_code' => 'ZONE006',
                'name' => 'St. Francis Zone',
                'description' => 'Northeast suburban zone',
                'area' => 'Northeast Suburbs',
                'boundaries' => 'Northeast Highway to County Line, bounded by Forest Road',
                'coordinator_name' => 'Patricia Martinez',
                'coordinator_phone' => '+1-555-0106',
                'coordinator_email' => 'patricia.martinez@parish.org',
                'active' => true,
                'display_order' => 6,
            ],
            [
                'zone_code' => 'ZONE007',
                'name' => 'St. Anthony Zone',
                'description' => 'Northwest residential zone',
                'area' => 'Northwest District',
                'boundaries' => 'Northwest Avenue to Lake Road, bounded by Hill Street',
                'coordinator_name' => 'Robert Anderson',
                'coordinator_phone' => '+1-555-0107',
                'coordinator_email' => 'robert.anderson@parish.org',
                'active' => true,
                'display_order' => 7,
            ],
            [
                'zone_code' => 'ZONE008',
                'name' => 'St. Thomas Zone',
                'description' => 'Southeast mixed-use zone',
                'area' => 'Southeast District',
                'boundaries' => 'Southeast Road to Business Park, bounded by Creek Road',
                'coordinator_name' => 'Jennifer Lee',
                'coordinator_phone' => '+1-555-0108',
                'coordinator_email' => 'jennifer.lee@parish.org',
                'active' => true,
                'display_order' => 8,
            ],
            [
                'zone_code' => 'ZONE009',
                'name' => 'St. Peter Zone',
                'description' => 'Southwest residential zone',
                'area' => 'Southwest District',
                'boundaries' => 'Southwest Boulevard to Valley Road, bounded by Mountain View',
                'coordinator_name' => 'David Taylor',
                'coordinator_phone' => '+1-555-0109',
                'coordinator_email' => 'david.taylor@parish.org',
                'active' => true,
                'display_order' => 9,
            ],
            [
                'zone_code' => 'ZONE010',
                'name' => 'Holy Family Zone',
                'description' => 'Central-North mixed residential zone',
                'area' => 'Central North',
                'boundaries' => 'Central Avenue to North Park, bounded by University Road',
                'coordinator_name' => 'Linda White',
                'coordinator_phone' => '+1-555-0110',
                'coordinator_email' => 'linda.white@parish.org',
                'active' => true,
                'display_order' => 10,
            ],
            [
                'zone_code' => 'ZONE011',
                'name' => 'St. Michael Zone',
                'description' => 'Rural zone covering outlying areas',
                'area' => 'Rural District',
                'boundaries' => 'County Road to Township Line, bounded by Farm Road',
                'coordinator_name' => 'Christopher Harris',
                'coordinator_phone' => '+1-555-0111',
                'coordinator_email' => 'christopher.harris@parish.org',
                'active' => true,
                'display_order' => 11,
            ],
            [
                'zone_code' => 'ZONE012',
                'name' => 'St. Paul Zone',
                'description' => 'Lakeside residential zone',
                'area' => 'Lakeside District',
                'boundaries' => 'Lake Shore Drive to Marina, bounded by Beach Road',
                'coordinator_name' => 'Nancy Clark',
                'coordinator_phone' => '+1-555-0112',
                'coordinator_email' => 'nancy.clark@parish.org',
                'active' => true,
                'display_order' => 12,
            ],
        ];

        DB::beginTransaction();
        
        try {
            foreach ($zones as $zoneData) {
                $zoneData['tenant_id'] = $tenant->id;
                $zoneData['created_by'] = 1; // Admin user
                $zoneData['updated_by'] = 1;
                
                ParishZone::create($zoneData);
                
                $this->command->info('✓ Created zone: ' . $zoneData['name']);
            }
            
            DB::commit();
            
            $this->command->info('');
            $this->command->info('✅ Successfully created ' . count($zones) . ' parish zones!');
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('Failed to create parish zones: ' . $e->getMessage());
        }
    }
}


