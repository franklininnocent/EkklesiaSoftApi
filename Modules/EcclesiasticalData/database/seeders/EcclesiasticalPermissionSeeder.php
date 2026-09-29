<?php

namespace Modules\EcclesiasticalData\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Modules\RolesAndPermissions\Models\Permission;

class EcclesiasticalPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $permissions = [
            ['name' => 'bishops.view', 'display_name' => 'View Bishops', 'description' => 'View bishop master records and leadership', 'module' => 'EcclesiasticalData', 'category' => 'bishops'],
            ['name' => 'bishops.create', 'display_name' => 'Create Bishops', 'description' => 'Create bishop person records', 'module' => 'EcclesiasticalData', 'category' => 'bishops'],
            ['name' => 'bishops.update', 'display_name' => 'Update Bishops', 'description' => 'Update bishop person records', 'module' => 'EcclesiasticalData', 'category' => 'bishops'],
            ['name' => 'bishops.archive', 'display_name' => 'Archive Bishops', 'description' => 'Archive bishop records with history', 'module' => 'EcclesiasticalData', 'category' => 'bishops'],
            ['name' => 'bishops.manage_appointments', 'display_name' => 'Manage Episcopal Appointments', 'description' => 'Create and end episcopal appointments', 'module' => 'EcclesiasticalData', 'category' => 'bishops'],
            ['name' => 'bishops.manage_history', 'display_name' => 'Manage Episcopal History', 'description' => 'Correct historical appointment records', 'module' => 'EcclesiasticalData', 'category' => 'bishops'],
            ['name' => 'bishops.manage_images', 'display_name' => 'Manage Bishop Images', 'description' => 'Upload bishop photos and coats of arms', 'module' => 'EcclesiasticalData', 'category' => 'bishops'],
            ['name' => 'bishops.review_requests', 'display_name' => 'Review Bishop Update Requests', 'description' => 'View church-submitted bishop update queue', 'module' => 'EcclesiasticalData', 'category' => 'bishops'],
            ['name' => 'bishops.approve_requests', 'display_name' => 'Approve Bishop Update Requests', 'description' => 'Approve and apply bishop update requests', 'module' => 'EcclesiasticalData', 'category' => 'bishops'],
            ['name' => 'bishops.reject_requests', 'display_name' => 'Reject Bishop Update Requests', 'description' => 'Reject bishop update requests', 'module' => 'EcclesiasticalData', 'category' => 'bishops'],
            ['name' => 'bishops.request_clarification', 'display_name' => 'Request Bishop Update Clarification', 'description' => 'Request clarification on bishop update requests', 'module' => 'EcclesiasticalData', 'category' => 'bishops'],
            ['name' => 'bishops.view_audit', 'display_name' => 'View Bishop Audit History', 'description' => 'View ecclesiastical audit history', 'module' => 'EcclesiasticalData', 'category' => 'bishops'],
            ['name' => 'dioceses.view', 'display_name' => 'View Dioceses', 'description' => 'View diocese master records', 'module' => 'EcclesiasticalData', 'category' => 'dioceses'],
            ['name' => 'dioceses.create', 'display_name' => 'Create Dioceses', 'description' => 'Create diocese master records', 'module' => 'EcclesiasticalData', 'category' => 'dioceses'],
            ['name' => 'dioceses.update', 'display_name' => 'Update Dioceses', 'description' => 'Update diocese master records', 'module' => 'EcclesiasticalData', 'category' => 'dioceses'],
            ['name' => 'dioceses.delete', 'display_name' => 'Delete Dioceses', 'description' => 'Delete diocese master records', 'module' => 'EcclesiasticalData', 'category' => 'dioceses'],
        ];

        foreach ($permissions as $permissionData) {
            Permission::updateOrCreate(
                ['name' => $permissionData['name']],
                array_merge($permissionData, [
                    'tenant_id' => null,
                    'is_custom' => false,
                    'scope' => Permission::SCOPE_PLATFORM,
                    'active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }

        $this->command?->info('✅ Ecclesiastical platform permissions seeded ('.count($permissions).')');
    }
}
