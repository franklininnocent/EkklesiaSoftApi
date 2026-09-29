<?php

namespace Modules\Tenants\Export\Contributors;

use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Tenants\Contracts\ExportWriterFactory;
use Modules\Tenants\Contracts\TenantDataExportContributor;

/**
 * Vertical-slice contributor: tenant users + tenant-scoped roles.
 * Never exports password, remember_token, or auth secrets.
 */
class UsersDataExportContributor implements TenantDataExportContributor
{
    public function key(): string
    {
        return 'users';
    }

    public function label(): string
    {
        return 'Users & roles';
    }

    public function defaultSelected(): bool
    {
        return true;
    }

    public function estimateCount(int $tenantId): int
    {
        $users = User::query()->where('tenant_id', $tenantId)->count();
        $roles = Role::query()->where('tenant_id', $tenantId)->count();

        return $users + $roles;
    }

    public function export(
        int $tenantId,
        ExportWriterFactory $writers,
        int $chunkSize,
        callable $onProgress
    ): array {
        $files = [];
        $files['data/users.csv'] = $this->exportUsers($tenantId, $writers, $chunkSize, $onProgress);
        $files['data/roles.csv'] = $this->exportRoles($tenantId, $writers, $chunkSize, $onProgress);

        return ['files' => $files];
    }

    private function exportUsers(
        int $tenantId,
        ExportWriterFactory $writers,
        int $chunkSize,
        callable $onProgress
    ): int {
        $relative = 'data/users.csv';
        $writer = $writers->create($relative);
        $writer->writeHeader([
            'user_id',
            'name',
            'email',
            'phone',
            'role_id',
            'role_name',
            'roles',
            'user_type',
            'is_primary_admin',
            'status',
            'email_verified_at',
            'created_at',
            'updated_at',
        ]);

        $count = 0;
        User::query()
            ->where('tenant_id', $tenantId)
            ->with(['role:id,name', 'roles:id,name'])
            ->orderBy('id')
            ->select([
                'id',
                'name',
                'email',
                'contact_number',
                'role_id',
                'user_type',
                'is_primary_admin',
                'active',
                'email_verified_at',
                'created_at',
                'updated_at',
            ])
            ->chunkById($chunkSize, function ($rows) use ($writer, &$count, $onProgress, $relative): void {
                foreach ($rows as $user) {
                    $roleNames = $user->roles
                        ->pluck('name')
                        ->filter()
                        ->unique()
                        ->values()
                        ->implode(', ');

                    $writer->writeRow([
                        $user->id,
                        $user->name,
                        $user->email,
                        $user->contact_number,
                        $user->role_id,
                        $user->role?->name,
                        $roleNames,
                        $user->user_type,
                        (bool) $user->is_primary_admin,
                        ((int) $user->active) === 1 ? 'active' : 'inactive',
                        $user->email_verified_at,
                        $user->created_at,
                        $user->updated_at,
                    ]);
                    $count++;
                }
                $onProgress($relative, $count);
            });

        $writer->finish();

        return $count;
    }

    private function exportRoles(
        int $tenantId,
        ExportWriterFactory $writers,
        int $chunkSize,
        callable $onProgress
    ): int {
        $relative = 'data/roles.csv';
        $writer = $writers->create($relative);
        $writer->writeHeader([
            'role_id',
            'name',
            'description',
            'level',
            'status',
            'is_custom',
            'role_classification',
            'created_at',
            'updated_at',
        ]);

        $count = 0;
        Role::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->select([
                'id',
                'name',
                'description',
                'level',
                'active',
                'is_custom',
                'role_classification',
                'created_at',
                'updated_at',
            ])
            ->chunkById($chunkSize, function ($rows) use ($writer, &$count, $onProgress, $relative): void {
                foreach ($rows as $role) {
                    $writer->writeRow([
                        $role->id,
                        $role->name,
                        $role->description,
                        $role->level,
                        ((int) $role->active) === 1 ? 'active' : 'inactive',
                        (bool) $role->is_custom,
                        $role->role_classification,
                        $role->created_at,
                        $role->updated_at,
                    ]);
                    $count++;
                }
                $onProgress($relative, $count);
            });

        $writer->finish();

        return $count;
    }
}
