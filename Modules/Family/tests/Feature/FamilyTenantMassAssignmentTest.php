<?php

namespace Modules\Family\Tests\Feature;

use Modules\BCC\Models\BCC;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FamilyTenantMassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function create_rejects_client_supplied_tenant_id(): void
    {
        $ctx = $this->actingAsTenantWith(['families.create', 'families.view']);
        $other = Tenant::factory()->active()->create();
        $bcc = BCC::factory()->create(['tenant_id' => $ctx['tenant']->id]);

        $this->postJson('/api/families', [
            'family_name' => 'Attempted Hop',
            'tenant_id' => $other->id,
            'bcc_id' => $bcc->id,
            'status' => 'active',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['tenant_id']);

        $this->assertSame(0, Family::query()->where('family_name', 'Attempted Hop')->count());
    }

    #[Test]
    public function update_does_not_change_tenant_id_when_sent_in_body(): void
    {
        $ctx = $this->actingAsTenantWith(['families.edit', 'families.view']);
        $other = Tenant::factory()->active()->create();
        $family = Family::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'family_name' => 'Stay Parish',
            'status' => 'active',
        ]);

        $this->putJson("/api/families/{$family->id}", [
            'family_name' => 'Stay Parish Renamed',
            'tenant_id' => $other->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['tenant_id']);

        $family->refresh();
        $this->assertSame((int) $ctx['tenant']->id, (int) $family->tenant_id);
        $this->assertSame('Stay Parish', $family->family_name);
    }

    /**
     * @return array{tenant: Tenant, user: \Modules\Authentication\Models\User}
     */
    protected function actingAsTenantWith(array $permissions): array
    {
        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->addYear(),
        ]);
        $role = \Modules\Authentication\Models\Role::create([
            'name' => 'Family Test '.uniqid(),
            'description' => 'Test',
            'level' => 3,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => true,
            'role_type' => \Modules\Authentication\Models\Role::ROLE_TYPE_TENANT,
        ]);
        $user = \Modules\Authentication\Models\User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'is_primary_admin' => true,
        ]);
        $user->syncRoles([$role->id]);

        foreach ($permissions as $name) {
            $permission = \Modules\RolesAndPermissions\Models\Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => 'Test',
                    'module' => 'Family',
                    'scope' => \Modules\RolesAndPermissions\Models\Permission::SCOPE_TENANT,
                    'category' => 'family',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->clearRequestPermissionCache();
        $user->clearPermissionsCache();

        \Laravel\Passport\Passport::actingAs($user->fresh());

        return ['tenant' => $tenant, 'user' => $user];
    }
}
