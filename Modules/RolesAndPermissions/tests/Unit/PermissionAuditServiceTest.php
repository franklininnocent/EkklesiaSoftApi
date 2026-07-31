<?php

namespace Modules\RolesAndPermissions\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Services\PermissionAuditService;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PermissionAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_writes_role_created_audit_row(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->create(['tenant_id' => $tenant->id]);
        $role = Role::create([
            'name' => 'Liturgical Coordinator',
            'description' => 'Role for audit test',
            'level' => 3,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $service = app(PermissionAuditService::class);
        $service->logRoleCreated($role, $actor);

        $this->assertDatabaseHas('permission_audit_logs', [
            'action' => 'role_created',
            'tenant_id' => $tenant->id,
        ]);

        $entry = \Illuminate\Support\Facades\DB::table('permission_audit_logs')
            ->where('action', 'role_created')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($entry);
        $metadata = json_decode((string) $entry->metadata, true);
        $this->assertSame($role->id, $metadata['role_id'] ?? null);
        $this->assertSame($actor->id, $metadata['created_by'] ?? null);
    }
}
