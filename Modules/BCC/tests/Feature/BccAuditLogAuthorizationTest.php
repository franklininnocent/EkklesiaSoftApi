<?php

namespace Modules\BCC\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCC;
use Modules\BCC\Testing\BccCertificationTestCase;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;

class BccAuditLogAuthorizationTest extends BccCertificationTestCase
{
    #[Test]
    public function non_primary_tenant_admin_cannot_view_audit_logs(): void
    {
        $tenant = Tenant::factory()->create();
        $role = Role::create([
            'name' => 'Parish Staff',
            'description' => 'Staff',
            'level' => 3,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $permission = Permission::updateOrCreate(
            ['name' => 'bcc.view'],
            [
                'display_name' => 'bcc.view',
                'description' => 'Test',
                'module' => 'BCC',
                'scope' => Permission::SCOPE_TENANT,
                'category' => 'bcc',
                'tenant_id' => null,
                'is_custom' => false,
                'active' => 1,
            ]
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'is_primary_admin' => false,
        ]);
        $user->syncRoles([$role->id]);

        $bcc = BCC::factory()->create(['tenant_id' => $tenant->id]);

        Passport::actingAs($user->fresh());

        $this->getJson('/api/bccs/audit-logs')->assertForbidden();
        $this->getJson("/api/bccs/{$bcc->id}/audit-logs")->assertForbidden();
    }
}
