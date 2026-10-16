<?php

namespace Modules\Tenants\Services;

use Illuminate\Support\Facades\Hash;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;

/**
 * Ensures the parish demo tenant has a known local primary admin (config-driven).
 * Not for production identity — demo seed guard must pass before orchestrator runs.
 */
final class DemoTenantAdminBootstrap
{
    public function ensurePrimaryAdmin(Tenant $tenant): User
    {
        $tenantId = (int) $tenant->id;
        $email = (string) config('tenants.demo.default_admin_email');
        $password = (string) config('tenants.demo.default_admin_password');
        $name = (string) config('tenants.demo.default_admin_name');

        $adminRole = $this->ensureAdministratorRole($tenantId);
        $admin = $this->resolveExistingAdmin($tenantId, $email);

        if ($admin === null) {
            if (User::withTrashed()->where('email', $email)->where('tenant_id', '!=', $tenantId)->exists()) {
                throw new \RuntimeException(
                    'Demo admin email '.$email.' is already used by another tenant. Use a fresh database or change DEMO_TENANT_ADMIN_EMAIL.'
                );
            }

            $admin = User::query()->create([
                'tenant_id' => $tenantId,
                'name' => $name,
                'email' => $email,
                'user_type' => User::USER_TYPE_PRIMARY_CONTACT,
                'is_primary_admin' => true,
                'role_id' => $adminRole->id,
                'password' => Hash::make($password),
                'active' => 1,
                'force_password_change' => false,
                'password_changed_at' => now(),
                'email_verified_at' => now(),
            ]);
        } else {
            $admin->forceFill([
                'name' => $name,
                'email' => $email,
                'tenant_id' => $tenantId,
                'is_primary_admin' => true,
                'role_id' => $adminRole->id,
                'password' => Hash::make($password),
                'active' => 1,
                'force_password_change' => false,
                'password_changed_at' => now(),
            ])->save();
        }

        $admin->roles()->syncWithoutDetaching([$adminRole->id]);

        return $admin->fresh() ?? $admin;
    }

    private function resolveExistingAdmin(int $tenantId, string $email): ?User
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($email): void {
                $query->where('is_primary_admin', true)
                    ->orWhere('email', $email);
            })
            ->orderByDesc('is_primary_admin')
            ->orderBy('id')
            ->first();
    }

    private function ensureAdministratorRole(int $tenantId): Role
    {
        $adminRole = Role::query()->firstOrCreate(
            [
                'name' => Role::TENANT_ADMINISTRATOR,
                'tenant_id' => $tenantId,
            ],
            [
                'description' => 'Parish administrator',
                'level' => 1,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_TENANT,
                'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
            ]
        );

        $tenantPermissions = Permission::query()
            ->where('active', 1)
            ->where('is_custom', false)
            ->whereNull('tenant_id')
            ->where(function ($query): void {
                $query->whereNull('scope')
                    ->orWhereIn('scope', [Permission::SCOPE_TENANT, Permission::SCOPE_BOTH]);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($tenantPermissions !== []) {
            $adminRole->permissions()->sync($tenantPermissions);
        }

        return $adminRole;
    }
}
