<?php

namespace Modules\Marketing\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\Role;
use Modules\RolesAndPermissions\Models\Permission;

class WebSettingsPermissionSeeder extends Seeder
{
    /** @var list<string> */
    private const ADMIN_PERMISSIONS = [
        'web_settings.view',
        'web_settings.update',
        'web_settings.send_test_email',
        'contact_enquiries.view',
        'contact_enquiries.update',
        'contact_enquiries.retry_email',
        'contact_enquiries.delete',
    ];

    public function run(): void
    {
        $definitions = [
            ['name' => 'web_settings.view', 'display_name' => 'View Web Settings', 'description' => 'View website contact, email diagnostics, and CAPTCHA settings'],
            ['name' => 'web_settings.update', 'display_name' => 'Update Web Settings', 'description' => 'Update website-wide contact and CAPTCHA settings'],
            ['name' => 'web_settings.send_test_email', 'display_name' => 'Send Web Settings Test Email', 'description' => 'Send a mail-transport test from Web Settings'],
            ['name' => 'contact_enquiries.view', 'display_name' => 'View Contact Enquiries', 'description' => 'View public website contact enquiries'],
            ['name' => 'contact_enquiries.update', 'display_name' => 'Update Contact Enquiries', 'description' => 'Update enquiry status and read state'],
            ['name' => 'contact_enquiries.retry_email', 'display_name' => 'Retry Contact Enquiry Email', 'description' => 'Retry failed contact enquiry email delivery'],
            ['name' => 'contact_enquiries.delete', 'display_name' => 'Delete Contact Enquiries', 'description' => 'Delete contact enquiries'],
        ];

        foreach ($definitions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                array_merge($permission, [
                    'module' => 'WebSettings',
                    'category' => 'website',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'scope' => Permission::SCOPE_PLATFORM,
                    'active' => 1,
                ])
            );
        }

        $this->syncRolePermissions(['SuperAdmin', 'EkklesiaAdmin'], self::ADMIN_PERMISSIONS);
    }

    /**
     * @param  list<string>  $roleNames
     * @param  list<string>  $permissionNames
     */
    private function syncRolePermissions(array $roleNames, array $permissionNames): void
    {
        $permissionIds = Permission::query()
            ->whereIn('name', $permissionNames)
            ->pluck('id');

        foreach (Role::query()->whereIn('name', $roleNames)->whereNull('tenant_id')->get() as $role) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $role->id, 'permission_id' => $permissionId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }
}
