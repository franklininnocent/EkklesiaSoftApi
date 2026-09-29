<?php

namespace Modules\MassIntentions\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Authentication\Models\Role;
use Modules\RolesAndPermissions\Models\Permission;

class MassIntentionsPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['name' => 'mass.intentions.view', 'display_name' => 'View Mass Intentions', 'description' => 'View Mass intention requests, schedule, and register', 'module' => 'MassIntentions', 'category' => 'mass_intentions'],
            ['name' => 'mass.intentions.create', 'display_name' => 'Create Mass Intentions', 'description' => 'Record new Mass intention requests', 'module' => 'MassIntentions', 'category' => 'mass_intentions'],
            ['name' => 'mass.intentions.review', 'display_name' => 'Close Mass Intentions', 'description' => 'Close open Mass intentions before or after the scheduled date', 'module' => 'MassIntentions', 'category' => 'mass_intentions'],
            ['name' => 'mass.intentions.schedule', 'display_name' => 'Schedule Mass Intentions', 'description' => 'Assign intentions to Masses and manage the Mass list', 'module' => 'MassIntentions', 'category' => 'mass_intentions'],
            ['name' => 'mass.intentions.fulfil', 'display_name' => 'Mark Mass Intentions as Said', 'description' => 'Confirm intentions were applied at Mass', 'module' => 'MassIntentions', 'category' => 'mass_intentions'],
            ['name' => 'mass.intentions.offerings.view', 'display_name' => 'View Mass Offerings', 'description' => 'View Mass offering amounts and receipts', 'module' => 'MassIntentions', 'category' => 'mass_intentions'],
            ['name' => 'mass.intentions.offerings.record', 'display_name' => 'Record Mass Offerings', 'description' => 'Record and void Mass offering receipts', 'module' => 'MassIntentions', 'category' => 'mass_intentions'],
            ['name' => 'mass.intentions.register.export', 'display_name' => 'Export Mass Register', 'description' => 'Export and print the canonical Mass intention register', 'module' => 'MassIntentions', 'category' => 'mass_intentions'],
            ['name' => 'mass.intentions.configure', 'display_name' => 'Configure Mass Intentions', 'description' => 'Parish settings for Mass intentions', 'module' => 'MassIntentions', 'category' => 'mass_intentions'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                array_merge($permission, [
                    'tenant_id' => null,
                    'is_custom' => false,
                    'scope' => Permission::SCOPE_TENANT,
                    'active' => 1,
                ])
            );
        }

        $this->assignToDefaultRoles();

        if ($this->command !== null) {
            $this->command->info('Mass Intentions permissions seeded.');
        }
    }

    private function assignToDefaultRoles(): void
    {
        $permissionIds = Permission::query()
            ->where('active', 1)
            ->where('name', 'like', 'mass.intentions.%')
            ->pluck('id')
            ->all();

        if ($permissionIds === []) {
            return;
        }

        Role::query()
            ->whereNotNull('tenant_id')
            ->whereIn('name', ['Administrator', 'Parish Priest'])
            ->each(function (Role $role) use ($permissionIds): void {
                $role->permissions()->syncWithoutDetaching($permissionIds);
                $role->clearUsersPermissionCache();
            });
    }
}
