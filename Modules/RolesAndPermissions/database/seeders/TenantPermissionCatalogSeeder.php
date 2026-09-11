<?php

namespace Modules\RolesAndPermissions\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\RolesAndPermissions\Models\Permission;

class TenantPermissionCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Church settings
            ['name' => 'church.settings.view', 'display_name' => 'View Church Settings', 'description' => 'View church settings', 'module' => 'ChurchSettings', 'category' => 'settings'],
            ['name' => 'church.settings.create', 'display_name' => 'Create Church Settings', 'description' => 'Create church settings', 'module' => 'ChurchSettings', 'category' => 'settings'],
            ['name' => 'church.settings.edit', 'display_name' => 'Edit Church Settings', 'description' => 'Edit church settings', 'module' => 'ChurchSettings', 'category' => 'settings'],
            ['name' => 'church.settings.delete', 'display_name' => 'Delete Church Settings', 'description' => 'Delete church settings', 'module' => 'ChurchSettings', 'category' => 'settings'],

            // Members
            ['name' => 'members.view', 'display_name' => 'View Members', 'description' => 'View members', 'module' => 'Members', 'category' => 'members'],
            ['name' => 'members.create', 'display_name' => 'Create Members', 'description' => 'Create members', 'module' => 'Members', 'category' => 'members'],
            ['name' => 'members.edit', 'display_name' => 'Edit Members', 'description' => 'Edit members', 'module' => 'Members', 'category' => 'members'],
            ['name' => 'members.delete', 'display_name' => 'Delete Members', 'description' => 'Delete members', 'module' => 'Members', 'category' => 'members'],

            // Families
            ['name' => 'families.view', 'display_name' => 'View Families', 'description' => 'View families', 'module' => 'Families', 'category' => 'families'],
            ['name' => 'families.create', 'display_name' => 'Create Families', 'description' => 'Create families', 'module' => 'Families', 'category' => 'families'],
            ['name' => 'families.edit', 'display_name' => 'Edit Families', 'description' => 'Edit families', 'module' => 'Families', 'category' => 'families'],
            ['name' => 'families.delete', 'display_name' => 'Delete Families', 'description' => 'Delete families', 'module' => 'Families', 'category' => 'families'],

            // Events
            ['name' => 'events.view', 'display_name' => 'View Events', 'description' => 'View events', 'module' => 'Events', 'category' => 'events'],
            ['name' => 'events.create', 'display_name' => 'Create Events', 'description' => 'Create events', 'module' => 'Events', 'category' => 'events'],
            ['name' => 'events.edit', 'display_name' => 'Edit Events', 'description' => 'Edit events', 'module' => 'Events', 'category' => 'events'],
            ['name' => 'events.delete', 'display_name' => 'Delete Events', 'description' => 'Delete events', 'module' => 'Events', 'category' => 'events'],

            // Attendance
            ['name' => 'attendance.view', 'display_name' => 'View Attendance', 'description' => 'View attendance', 'module' => 'Attendance', 'category' => 'attendance'],
            ['name' => 'attendance.create', 'display_name' => 'Create Attendance', 'description' => 'Create attendance records', 'module' => 'Attendance', 'category' => 'attendance'],
            ['name' => 'attendance.edit', 'display_name' => 'Edit Attendance', 'description' => 'Edit attendance records', 'module' => 'Attendance', 'category' => 'attendance'],
            ['name' => 'attendance.delete', 'display_name' => 'Delete Attendance', 'description' => 'Delete attendance records', 'module' => 'Attendance', 'category' => 'attendance'],

            // Donations
            ['name' => 'donations.view', 'display_name' => 'View Donations', 'description' => 'View donations', 'module' => 'Donations', 'category' => 'donations'],
            ['name' => 'donations.create', 'display_name' => 'Create Donations', 'description' => 'Create donations', 'module' => 'Donations', 'category' => 'donations'],
            ['name' => 'donations.edit', 'display_name' => 'Edit Donations', 'description' => 'Edit donations', 'module' => 'Donations', 'category' => 'donations'],
            ['name' => 'donations.delete', 'display_name' => 'Delete Donations', 'description' => 'Delete donations', 'module' => 'Donations', 'category' => 'donations'],

            // Ministries & Associations
            ['name' => 'ministries.view', 'display_name' => 'View Ministries & Associations', 'description' => 'View organizations and ministry data', 'module' => 'MinistriesAssociations', 'category' => 'ministries'],
            ['name' => 'ministries.create', 'display_name' => 'Create Organizations', 'description' => 'Create ministries and associations', 'module' => 'MinistriesAssociations', 'category' => 'ministries'],
            ['name' => 'ministries.edit', 'display_name' => 'Edit Organizations', 'description' => 'Update organization profiles and status', 'module' => 'MinistriesAssociations', 'category' => 'ministries'],
            ['name' => 'ministries.delete', 'display_name' => 'Delete Organizations', 'description' => 'Archive and restore organizations', 'module' => 'MinistriesAssociations', 'category' => 'ministries'],
            ['name' => 'ministries.manage_members', 'display_name' => 'Manage Ministry Members', 'description' => 'Enroll members, manage guests, and parishioner lookup', 'module' => 'MinistriesAssociations', 'category' => 'ministries'],
            ['name' => 'ministries.manage_leadership', 'display_name' => 'Manage Ministry Leadership', 'description' => 'Assign, terminate, and hand over leadership terms', 'module' => 'MinistriesAssociations', 'category' => 'ministries'],
            ['name' => 'ministries.configure', 'display_name' => 'Configure Ministries Taxonomies', 'description' => 'Manage categories, types, positions, and seed defaults', 'module' => 'MinistriesAssociations', 'category' => 'ministries'],

            // BCC
            ['name' => 'bcc.view', 'display_name' => 'View BCCs', 'description' => 'View BCC dashboard, list, members, history, and audit', 'module' => 'BCC', 'category' => 'bcc'],
            ['name' => 'bcc.create', 'display_name' => 'Create BCCs', 'description' => 'Create Basic Christian Communities', 'module' => 'BCC', 'category' => 'bcc'],
            ['name' => 'bcc.edit', 'display_name' => 'Edit BCCs', 'description' => 'Update BCC profiles and status', 'module' => 'BCC', 'category' => 'bcc'],
            ['name' => 'bcc.delete', 'display_name' => 'Delete BCCs', 'description' => 'Soft-delete BCCs and unassign families', 'module' => 'BCC', 'category' => 'bcc'],
            ['name' => 'bcc.manage_members', 'display_name' => 'Manage BCC Members', 'description' => 'Assign and remove families from a BCC', 'module' => 'BCC', 'category' => 'bcc'],
            ['name' => 'bcc.manage_leadership', 'display_name' => 'Manage BCC Leadership', 'description' => 'Assign, end, and hand over BCC leadership', 'module' => 'BCC', 'category' => 'bcc'],

            // Sacraments registry
            ['name' => 'sacraments.view', 'display_name' => 'View Sacraments', 'description' => 'View sacramental records', 'module' => 'Sacraments', 'category' => 'sacraments'],
            ['name' => 'sacraments.create', 'display_name' => 'Create Sacraments', 'description' => 'Create sacramental records', 'module' => 'Sacraments', 'category' => 'sacraments'],
            ['name' => 'sacraments.edit', 'display_name' => 'Edit Sacraments', 'description' => 'Edit sacramental metadata / legacy fields', 'module' => 'Sacraments', 'category' => 'sacraments'],
            ['name' => 'sacraments.correct', 'display_name' => 'Correct Sacraments', 'description' => 'Post-registration correction with reason and audit', 'module' => 'Sacraments', 'category' => 'sacraments'],
            ['name' => 'sacraments.void', 'display_name' => 'Void Sacraments', 'description' => 'Void sacramental records (business invalid)', 'module' => 'Sacraments', 'category' => 'sacraments'],
            ['name' => 'sacraments.delete', 'display_name' => 'Delete Sacraments', 'description' => 'Soft-delete sacramental records', 'module' => 'Sacraments', 'category' => 'sacraments'],
            ['name' => 'sacraments.restore', 'display_name' => 'Restore Sacraments', 'description' => 'Restore soft-deleted records (does not unvoid)', 'module' => 'Sacraments', 'category' => 'sacraments'],
            ['name' => 'sacraments.export', 'display_name' => 'Export Sacraments', 'description' => 'Export sacramental records', 'module' => 'Sacraments', 'category' => 'sacraments'],
            ['name' => 'sacraments.view_restricted', 'display_name' => 'View Restricted Sacraments', 'description' => 'View and create restricted sacramental records (e.g. Reconciliation)', 'module' => 'Sacraments', 'category' => 'sacraments'],
            ['name' => 'certificate.generate', 'display_name' => 'Generate Certificates', 'description' => 'Preview and generate official sacramental certificates', 'module' => 'Sacraments', 'category' => 'sacraments'],
            ['name' => 'certificate.download', 'display_name' => 'Download Certificates', 'description' => 'Download issued sacramental certificates', 'module' => 'Sacraments', 'category' => 'sacraments'],
            ['name' => 'certificate.reissue', 'display_name' => 'Reissue Certificates', 'description' => 'Reissue / supersede sacramental certificates', 'module' => 'Sacraments', 'category' => 'sacraments'],
            ['name' => 'sacraments.migration.view', 'display_name' => 'View Sacrament Migration Queue', 'description' => 'View migration resolutions and migration report', 'module' => 'Sacraments', 'category' => 'sacraments'],
            ['name' => 'sacraments.migration.resolve', 'display_name' => 'Resolve Sacrament Migration', 'description' => 'Resolve unresolved participants and run backfill', 'module' => 'Sacraments', 'category' => 'sacraments'],
            ['name' => 'sacraments.settings.view', 'display_name' => 'View Sacrament Settings', 'description' => 'View which sacrament types are available for this church', 'module' => 'Sacraments', 'category' => 'sacraments'],
            ['name' => 'sacraments.settings.manage', 'display_name' => 'Manage Sacrament Settings', 'description' => 'Activate or deactivate sacrament types for this church', 'module' => 'Sacraments', 'category' => 'sacraments'],

            // Reports
            ['name' => 'reports.view', 'display_name' => 'View Reports', 'description' => 'View reports', 'module' => 'Reports', 'category' => 'reports'],
            ['name' => 'reports.export', 'display_name' => 'Export Reports', 'description' => 'Export reports', 'module' => 'Reports', 'category' => 'reports'],

            // Tenant data export (Settings → Data Export)
            ['name' => 'tenant.data.export', 'display_name' => 'Export Tenant Data', 'description' => 'Request, view, and download parish business data exports', 'module' => 'TenantDataExport', 'category' => 'settings'],

            // Default Seeds (Settings → Default Seeds)
            ['name' => 'settings.default-seeds.view', 'display_name' => 'View Default Seeds', 'description' => 'View recommended default configuration for enabled modules', 'module' => 'TenantDefaultSeeds', 'category' => 'settings'],
            ['name' => 'settings.default-seeds.run', 'display_name' => 'Add Default Seeds', 'description' => 'Add missing recommended default configuration for enabled modules', 'module' => 'TenantDefaultSeeds', 'category' => 'settings'],

            // Pastoral care (staff-only visit requests)
            ['name' => 'pastoral.care.view', 'display_name' => 'View Pastoral Care', 'description' => 'View visit requests and pastoral workflow', 'module' => 'PastoralCare', 'category' => 'pastoral'],
            ['name' => 'pastoral.care.create', 'display_name' => 'Request a Visit', 'description' => 'Create pastoral visit requests for a family', 'module' => 'PastoralCare', 'category' => 'pastoral'],
            ['name' => 'pastoral.care.assign', 'display_name' => 'Assign Pastoral Follow-ups', 'description' => 'Assign visit requests to pastoral staff', 'module' => 'PastoralCare', 'category' => 'pastoral'],

            // Support Access (parish self-serve windows)
            ['name' => 'support.grants.parish.view', 'display_name' => 'View Support Access Windows', 'description' => 'View customer-granted support access windows for this parish', 'module' => 'SupportAccess', 'category' => 'support'],
            ['name' => 'support.grants.parish.manage', 'display_name' => 'Manage Support Access Windows', 'description' => 'Create and revoke support access windows for this parish', 'module' => 'SupportAccess', 'category' => 'support'],

            // Episcopal leadership (read + church-submitted corrections)
            ['name' => 'bishops.view', 'display_name' => 'View Diocesan Bishop', 'description' => 'View diocesan bishop information for this church', 'module' => 'EcclesiasticalData', 'category' => 'bishops'],
            ['name' => 'bishops.submit_update_request', 'display_name' => 'Submit Bishop Update Request', 'description' => 'Submit bishop correction requests to Ekklesia administrators', 'module' => 'EcclesiasticalData', 'category' => 'bishops'],
            ['name' => 'bishops.view_own_requests', 'display_name' => 'View Own Bishop Update Requests', 'description' => 'View bishop update requests submitted by this church', 'module' => 'EcclesiasticalData', 'category' => 'bishops'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                array_merge($permission, [
                    'tenant_id' => null,
                    'is_custom' => false,
                    'scope' => Permission::SCOPE_TENANT,
                    'active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
