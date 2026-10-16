<?php

namespace Modules\SupportTickets\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\Role;
use Modules\RolesAndPermissions\Models\Permission;

class SupportTicketsPermissionSeeder extends Seeder
{
    /** @var list<string> */
    private const OPS_ADMIN = [
        'support.ops.tickets.view',
        'support.ops.tickets.assign',
        'support.ops.tickets.comment',
        'support.ops.tickets.internal_note',
        'support.ops.tickets.change_priority',
        'support.ops.tickets.change_status',
        'support.ops.tickets.resolve',
        'support.ops.tickets.close',
        'support.ops.tickets.reopen',
    ];

    /** @var list<string> */
    private const OPS_MANAGER = [
        'support.ops.tickets.view',
        'support.ops.tickets.assign',
        'support.ops.tickets.comment',
        'support.ops.tickets.internal_note',
        'support.ops.tickets.change_priority',
        'support.ops.tickets.change_status',
        'support.ops.tickets.resolve',
        'support.ops.tickets.reopen',
    ];

    /** @var list<string> */
    private const OPS_AGENT = [
        'support.ops.tickets.view',
        'support.ops.tickets.assign',
        'support.ops.tickets.comment',
        'support.ops.tickets.internal_note',
        'support.ops.tickets.change_status',
    ];

    public function run(): void
    {
        $tenantPermissions = [
            ['name' => 'support.tickets.view', 'display_name' => 'View Support Tickets', 'description' => 'View own and participant support tickets'],
            ['name' => 'support.tickets.create', 'display_name' => 'Create Support Tickets', 'description' => 'Create support tickets'],
            ['name' => 'support.tickets.comment', 'display_name' => 'Comment on Support Tickets', 'description' => 'Add public comments to support tickets'],
            ['name' => 'support.tickets.attachments', 'display_name' => 'Attach Files to Tickets', 'description' => 'Upload attachments to support tickets'],
            ['name' => 'support.tickets.participants', 'display_name' => 'Manage Ticket Participants', 'description' => 'Add or remove ticket participants'],
            ['name' => 'support.tickets.reopen', 'display_name' => 'Reopen Support Tickets', 'description' => 'Reopen resolved support tickets within the allowed window'],
            ['name' => 'support.tickets.cancel', 'display_name' => 'Cancel Support Tickets', 'description' => 'Cancel open support tickets'],
            ['name' => 'support.tickets.resolve', 'display_name' => 'Resolve Support Tickets', 'description' => 'Mark support tickets as resolved when the issue is fixed'],
            ['name' => 'support.tickets.view_all_tenant', 'display_name' => 'View All Parish Tickets', 'description' => 'View all support tickets for this parish'],
        ];

        foreach ($tenantPermissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                array_merge($permission, [
                    'module' => 'SupportTickets',
                    'category' => 'support',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'scope' => Permission::SCOPE_TENANT,
                    'active' => 1,
                ])
            );
        }

        $opsPermissions = [
            ['name' => 'support.ops.tickets.view', 'display_name' => 'View Support Tickets (Ops)', 'description' => 'View support tickets across tenants'],
            ['name' => 'support.ops.tickets.assign', 'display_name' => 'Assign Support Tickets', 'description' => 'Assign queues and agents to tickets'],
            ['name' => 'support.ops.tickets.comment', 'display_name' => 'Reply on Support Tickets', 'description' => 'Add public replies to support tickets'],
            ['name' => 'support.ops.tickets.internal_note', 'display_name' => 'Internal Support Notes', 'description' => 'Add internal notes visible only to Ekklesia support'],
            ['name' => 'support.ops.tickets.change_priority', 'display_name' => 'Change Ticket Priority', 'description' => 'Change support ticket priority'],
            ['name' => 'support.ops.tickets.change_status', 'display_name' => 'Change Ticket Status', 'description' => 'Change support ticket status'],
            ['name' => 'support.ops.tickets.resolve', 'display_name' => 'Resolve Support Tickets', 'description' => 'Resolve support tickets'],
            ['name' => 'support.ops.tickets.close', 'display_name' => 'Close Support Tickets', 'description' => 'Close support tickets'],
            ['name' => 'support.ops.tickets.reopen', 'display_name' => 'Reopen Support Tickets (Ops)', 'description' => 'Reopen support tickets from ops'],
        ];

        foreach ($opsPermissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                array_merge($permission, [
                    'module' => 'SupportTickets',
                    'category' => 'support',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'scope' => Permission::SCOPE_PLATFORM,
                    'active' => 1,
                ])
            );
        }

        $this->syncRolePermissions(['SuperAdmin', 'EkklesiaAdmin'], self::OPS_ADMIN);
        $this->syncRolePermissions(['EkklesiaManager'], self::OPS_MANAGER);
        $this->syncRolePermissions(['EkklesiaUser', 'SupportAdmin'], self::OPS_AGENT);

        $priestExcluded = ['support.tickets.view_all_tenant', 'support.tickets.cancel'];
        $priestIds = Permission::query()
            ->whereIn('name', array_diff(array_column($tenantPermissions, 'name'), $priestExcluded))
            ->pluck('id')
            ->all();

        Role::query()
            ->whereNotNull('tenant_id')
            ->where('name', 'Parish Priest')
            ->each(function (Role $role) use ($priestIds): void {
                $role->permissions()->syncWithoutDetaching($priestIds);
            });
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

        foreach (Role::query()->whereIn('name', $roleNames)->get() as $role) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $role->id, 'permission_id' => $permissionId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }
}
