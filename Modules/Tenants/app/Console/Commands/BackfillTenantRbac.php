<?php

namespace Modules\Tenants\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;

class BackfillTenantRbac extends Command
{
    protected $signature = 'tenants:backfill-rbac
        {--tenant-id= : Backfill a specific tenant only}
        {--dry-run : Preview changes without writing}
        {--delete-deprecated : Delete deprecated default roles}
        {--reassign-to-role= : Reassign users before deleting deprecated roles}';

    protected $description = 'Backfill tenant RBAC defaults (role catalog + Administrator role_user pivot sync).';

    public function handle(): int
    {
        $tenantId = $this->option('tenant-id');
        $dryRun = (bool) $this->option('dry-run');
        $deleteDeprecated = (bool) $this->option('delete-deprecated');
        $reassignToRole = trim((string) ($this->option('reassign-to-role') ?? ''));

        if (!$deleteDeprecated && $reassignToRole !== '') {
            $this->warn('--reassign-to-role is ignored unless --delete-deprecated is provided.');
        }

        $defaultRoles = [
            ['name' => 'Parish Priest', 'description' => 'Parish Priest', 'level' => 2],
        ];
        $deprecatedDefaultRoleNames = [
            'Pastor',
            'Assistant Pastor',
            'Secretary',
            'Treasurer',
            'Ministry Leader',
            'Member Coordinator',
            'Volunteer',
        ];

        $tenantQuery = Tenant::query();
        if ($tenantId) {
            $tenantQuery->where('id', $tenantId);
        }

        $tenants = $tenantQuery->get();
        if ($tenants->isEmpty()) {
            $this->warn('No tenants found for backfill.');
            return self::SUCCESS;
        }

        $summary = [
            'tenants' => $tenants->count(),
            'admin_roles_created' => 0,
            'default_roles_created' => 0,
            'default_roles_renamed' => 0,
            'deprecated_default_roles_removed' => 0,
            'deprecated_default_roles_skipped' => 0,
            'deprecated_default_roles_reassigned' => 0,
            'pivot_rows_added' => 0,
        ];
        $hasRoleClassification = Schema::hasColumn('roles', 'role_classification');
        $tenantPermissionCatalog = Permission::query()
            ->where('active', 1)
            ->where('is_custom', false)
            ->whereNull('tenant_id')
            ->where(function ($query) {
                $query->whereNull('scope')
                    ->orWhereIn('scope', [Permission::SCOPE_TENANT, Permission::SCOPE_BOTH]);
            })
            ->get(['id', 'name']);
        $tenantPermissionIds = $tenantPermissionCatalog
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
        $parishPriestPermissionIds = $this->buildParishPriestPermissionIds($tenantPermissionCatalog);

        foreach ($tenants as $tenant) {
            DB::transaction(function () use ($tenant, $defaultRoles, $deprecatedDefaultRoleNames, $tenantPermissionIds, $parishPriestPermissionIds, $dryRun, $hasRoleClassification, $deleteDeprecated, $reassignToRole, &$summary) {
                $adminRole = Role::where('tenant_id', $tenant->id)
                    ->where('name', Role::TENANT_ADMINISTRATOR)
                    ->first();

                if (!$adminRole) {
                    $summary['admin_roles_created']++;
                    if (!$dryRun) {
                        $adminRoleData = [
                            'name' => Role::TENANT_ADMINISTRATOR,
                            'description' => "{$tenant->name} Super Administrator",
                            'level' => 1,
                            'tenant_id' => $tenant->id,
                            'is_custom' => false,
                            'role_type' => Role::ROLE_TYPE_TENANT,
                            'active' => 1,
                        ];
                        if ($hasRoleClassification) {
                            $adminRoleData['role_classification'] = Role::CLASSIFICATION_PROTECTED_SYSTEM;
                        }
                        $adminRole = Role::create($adminRoleData);
                    }
                }

                if (
                    $hasRoleClassification &&
                    $adminRole &&
                    $adminRole->role_classification !== Role::CLASSIFICATION_PROTECTED_SYSTEM &&
                    !$dryRun
                ) {
                    $adminRole->update(['role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM]);
                }

                foreach ($defaultRoles as $defaultRole) {
                    $role = Role::where('tenant_id', $tenant->id)
                        ->where('name', $defaultRole['name'])
                        ->first();

                    // Canonicalize legacy Pastor naming into Parish Priest when possible.
                    if (!$role) {
                        $legacyPastorRole = Role::where('tenant_id', $tenant->id)
                            ->where('name', 'Pastor')
                            ->where('role_type', Role::ROLE_TYPE_TENANT)
                            ->first();

                        if ($legacyPastorRole) {
                            $role = $legacyPastorRole;
                            if (!$dryRun) {
                                $legacyPastorRole->update([
                                    'name' => $defaultRole['name'],
                                    'description' => "{$tenant->name} {$defaultRole['description']}",
                                    'level' => $defaultRole['level'],
                                ]);
                            }
                            $summary['default_roles_renamed']++;
                        }
                    }

                    if (!$role) {
                        $summary['default_roles_created']++;
                        if (!$dryRun) {
                            $defaultRoleData = [
                                'name' => $defaultRole['name'],
                                'description' => "{$tenant->name} {$defaultRole['description']}",
                                'level' => $defaultRole['level'],
                                'tenant_id' => $tenant->id,
                                'is_custom' => false,
                                'role_type' => Role::ROLE_TYPE_TENANT,
                                'active' => 1,
                            ];
                            if ($hasRoleClassification) {
                                $defaultRoleData['role_classification'] = Role::CLASSIFICATION_DEFAULT_TEMPLATE;
                            }
                            $role = Role::create($defaultRoleData);
                        }
                    } else if (!$dryRun) {
                        $updatePayload = [
                            'role_type' => Role::ROLE_TYPE_TENANT,
                            'is_custom' => false,
                        ];
                        if ($hasRoleClassification) {
                            $updatePayload['role_classification'] = Role::CLASSIFICATION_DEFAULT_TEMPLATE;
                        }
                        $role->update($updatePayload);
                    }

                    if (
                        !$dryRun &&
                        $role &&
                        strtolower($role->name) === 'parish priest' &&
                        !empty($parishPriestPermissionIds)
                    ) {
                        // Ensure Parish Priest keeps the default operational permission baseline.
                        $role->permissions()->sync($parishPriestPermissionIds);
                    }
                }

                $deprecatedRoles = Role::where('tenant_id', $tenant->id)
                    ->whereIn('name', $deprecatedDefaultRoleNames)
                    ->where('role_type', Role::ROLE_TYPE_TENANT)
                    ->where('is_custom', false)
                    ->get();

                foreach ($deprecatedRoles as $deprecatedRole) {
                    if (!$deleteDeprecated) {
                        $summary['deprecated_default_roles_skipped']++;
                        continue;
                    }

                    $assignedUserIds = $this->resolveAssignedUserIds((int) $deprecatedRole->id);
                    if (!empty($assignedUserIds)) {
                        if ($reassignToRole === '') {
                            $summary['deprecated_default_roles_skipped']++;
                            continue;
                        }

                        $fallbackRole = Role::where('tenant_id', $tenant->id)
                            ->where('name', $reassignToRole)
                            ->where('active', 1)
                            ->where('role_type', Role::ROLE_TYPE_TENANT)
                            ->first();

                        if (!$fallbackRole || (int) $fallbackRole->id === (int) $deprecatedRole->id) {
                            $summary['deprecated_default_roles_skipped']++;
                            continue;
                        }

                        if (!$dryRun) {
                            DB::table('role_user')
                                ->where('role_id', $deprecatedRole->id)
                                ->whereIn('user_id', $assignedUserIds)
                                ->delete();

                            foreach ($assignedUserIds as $userId) {
                                DB::table('role_user')->updateOrInsert(
                                    ['user_id' => $userId, 'role_id' => $fallbackRole->id],
                                    ['created_at' => now(), 'updated_at' => now()]
                                );
                            }

                            User::whereIn('id', $assignedUserIds)
                                ->where('role_id', $deprecatedRole->id)
                                ->update(['role_id' => $fallbackRole->id]);
                        }

                        $summary['deprecated_default_roles_reassigned'] += count($assignedUserIds);
                    }

                    if (!$dryRun) {
                        $deprecatedRole->permissions()->detach();
                        DB::table('role_user')->where('role_id', $deprecatedRole->id)->delete();
                        $deprecatedRole->delete();
                    }
                    $summary['deprecated_default_roles_removed']++;
                }

                if ($adminRole) {
                    $usersToSync = User::where('tenant_id', $tenant->id)
                        ->where(function ($query) use ($adminRole) {
                            $query->where('role_id', $adminRole->id)
                                ->orWhere('is_primary_admin', true);
                        })
                        ->get();

                    foreach ($usersToSync as $user) {
                        $alreadyAttached = DB::table('role_user')
                            ->where('user_id', $user->id)
                            ->where('role_id', $adminRole->id)
                            ->exists();
                        if (!$alreadyAttached) {
                            $summary['pivot_rows_added']++;
                            if (!$dryRun) {
                                $user->roles()->syncWithoutDetaching([$adminRole->id]);
                            }
                        }
                    }
                }
            });
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Tenants processed', $summary['tenants']],
                ['Administrator roles created', $summary['admin_roles_created']],
                ['Default tenant roles created', $summary['default_roles_created']],
                ['Default roles renamed', $summary['default_roles_renamed']],
                ['Deprecated default roles removed', $summary['deprecated_default_roles_removed']],
                ['Deprecated roles skipped', $summary['deprecated_default_roles_skipped']],
                ['Users reassigned from deprecated roles', $summary['deprecated_default_roles_reassigned']],
                ['role_user rows added', $summary['pivot_rows_added']],
            ]
        );

        if ($dryRun) {
            $this->info('Dry run complete. No data was modified.');
        } else {
            $this->info('Tenant RBAC backfill completed.');
        }

        return self::SUCCESS;
    }

    /**
     * Read user assignments from both legacy users.role_id and modern role_user pivot.
     */
    private function resolveAssignedUserIds(int $roleId): array
    {
        $pivotUserIds = DB::table('role_user')
            ->where('role_id', $roleId)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $legacyUserIds = User::where('role_id', $roleId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($pivotUserIds, $legacyUserIds)));
    }

    private function buildParishPriestPermissionIds($permissions): array
    {
        return $permissions
            ->reject(function (Permission $permission) {
                return $this->isGovernancePermissionName($permission->name);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function isGovernancePermissionName(string $permissionName): bool
    {
        $governancePrefixes = [
            'roles.',
            'permissions.',
            'users.assign',
            'users.create',
            'users.update',
            'users.delete',
            'church.settings.',
            'settings.',
            'security.',
            'integration.',
            'integrations.',
            'subscription.',
            'billing.',
            'tenants.',
            'pope.',
        ];

        foreach ($governancePrefixes as $prefix) {
            if (str_starts_with($permissionName, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
