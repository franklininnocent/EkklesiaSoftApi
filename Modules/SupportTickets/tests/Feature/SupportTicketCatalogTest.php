<?php

namespace Modules\SupportTickets\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\SupportTickets\Database\Seeders\SupportTicketsDatabaseSeeder;
use Modules\SupportTickets\Models\SupportCategory;
use Modules\SupportTickets\Models\SupportRequestType;
use Modules\SupportTickets\Models\SupportTicket;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class SupportTicketCatalogTest extends TestCase
{
    use ActsAsTenantRoles;

    private Tenant $tenant;

    private User $tenantUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SupportTicketsDatabaseSeeder::class);
        $this->seedSupportConfigurationPermission();

        $this->tenant = $this->makeOperationalTenant();
        $this->tenantUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'active' => 1,
        ]);
        $this->grantTenantPermissions($this->tenantUser, [
            'support.tickets.view',
            'support.tickets.create',
        ]);
    }

    #[Test]
    public function ops_can_create_request_type_and_tenant_lookups_include_it(): void
    {
        $this->asSuperAdmin();

        $this->postJson('/api/support/ticket-catalog/request-types', [
            'name' => 'Parish Website Help',
            'sort_order' => 99,
            'active' => true,
            'requires_bug_fields' => false,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Parish Website Help');

        Passport::actingAs($this->tenantUser);
        $this->getJson('/api/tenant/support/lookups')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Parish Website Help']);
    }

    #[Test]
    public function inactive_request_type_is_hidden_from_tenant_lookups_and_create_fails(): void
    {
        $type = SupportRequestType::query()->create([
            'slug' => 'hidden-type',
            'name' => 'Hidden Type',
            'sort_order' => 50,
            'active' => false,
            'requires_bug_fields' => false,
        ]);

        Passport::actingAs($this->tenantUser);
        $response = $this->getJson('/api/tenant/support/lookups')->assertOk();
        $names = collect($response->json('data.request_types'))->pluck('name')->all();
        $this->assertNotContains('Hidden Type', $names);

        $this->postJson('/api/tenant/support/tickets', [
            'request_type_id' => $type->id,
            'subject' => 'Blocked',
            'description' => 'Should fail',
            'submit' => true,
        ])->assertUnprocessable();
    }

    #[Test]
    public function tenant_cannot_access_ops_catalog_routes(): void
    {
        Passport::actingAs($this->tenantUser);

        $this->getJson('/api/support/ticket-catalog/request-types')->assertForbidden();
        $this->postJson('/api/support/ticket-catalog/request-types', [
            'name' => 'Nope',
        ])->assertForbidden();
    }

    #[Test]
    public function delete_is_blocked_when_request_type_has_tickets(): void
    {
        $type = SupportRequestType::query()->firstOrFail();
        SupportTicket::factory()->create([
            'tenant_id' => $this->tenant->id,
            'requester_user_id' => $this->tenantUser->id,
            'request_type_id' => $type->id,
        ]);

        $this->asSuperAdmin();
        $this->deleteJson('/api/support/ticket-catalog/request-types/'.$type->id)
            ->assertStatus(409);
    }

    #[Test]
    public function deactivating_request_type_hides_it_from_tenant_dropdown(): void
    {
        $this->asSuperAdmin();
        $type = SupportRequestType::query()->where('slug', 'other')->firstOrFail();

        $this->putJson('/api/support/ticket-catalog/request-types/'.$type->id, [
            'name' => $type->name,
            'sort_order' => $type->sort_order,
            'active' => false,
            'requires_bug_fields' => false,
        ])->assertOk();

        Passport::actingAs($this->tenantUser);
        $names = collect($this->getJson('/api/tenant/support/lookups')->json('data.request_types'))
            ->pluck('name')
            ->all();
        $this->assertNotContains($type->name, $names);
    }

    #[Test]
    public function category_must_belong_to_selected_request_type(): void
    {
        $typeA = SupportRequestType::query()->where('slug', 'technical_issue')->firstOrFail();
        $typeB = SupportRequestType::query()->where('slug', 'bug_report')->firstOrFail();
        $category = SupportCategory::query()->create([
            'request_type_id' => $typeB->id,
            'name' => 'Wrong bucket',
            'sort_order' => 1,
            'active' => true,
        ]);

        Passport::actingAs($this->tenantUser);
        $this->postJson('/api/tenant/support/tickets', [
            'request_type_id' => $typeA->id,
            'category_id' => $category->id,
            'subject' => 'Mismatch',
            'description' => 'Should fail',
            'submit' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    #[Test]
    public function parish_home_ekklesia_admin_can_list_catalog_without_support_session(): void
    {
        // Legacy / edge case: platform role linked while tenant_id is set (syncRoles blocks this).
        $admin = $this->makePlatformOperator(
            Role::EKKLESIA_ADMIN,
            Role::LEVEL_EKKLESIA_ADMIN,
            Role::ROLE_TYPE_PLATFORM,
            ['support.configuration.manage'],
            null,
        );
        $admin->forceFill(['tenant_id' => $this->tenant->id])->save();

        Passport::actingAs($admin->fresh());

        $this->assertFalse(
            $admin->fresh()->hasPermission('support.configuration.manage'),
            'hasPermission stays session-gated for parish-home operators'
        );

        $this->getJson('/api/support/ticket-catalog/request-types')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [['id', 'name', 'active', 'categories']]]);

        $this->getJson('/api/support/settings')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function null_tenant_ekklesia_admin_can_list_catalog(): void
    {
        $admin = $this->makePlatformOperator(
            Role::EKKLESIA_ADMIN,
            Role::LEVEL_EKKLESIA_ADMIN,
            Role::ROLE_TYPE_PLATFORM,
            ['support.configuration.manage'],
            null,
        );

        Passport::actingAs($admin);

        $this->getJson('/api/support/ticket-catalog/request-types')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function support_admin_without_configuration_permission_cannot_list_catalog(): void
    {
        $operator = $this->makePlatformOperator(
            Role::SUPPORT_ADMIN,
            Role::LEVEL_SUPPORT_ADMIN,
            Role::ROLE_TYPE_SUPPORT,
            ['support.sessions.view', 'support.sessions.start'],
            null,
        );

        Passport::actingAs($operator);

        $this->getJson('/api/support/ticket-catalog/request-types')
            ->assertForbidden()
            ->assertJsonPath('missing_permissions.0', 'support.configuration.manage');
    }

    #[Test]
    public function ekklesia_manager_without_configuration_permission_cannot_list_catalog(): void
    {
        $manager = $this->makePlatformOperator(
            Role::EKKLESIA_MANAGER,
            Role::LEVEL_EKKLESIA_MANAGER,
            Role::ROLE_TYPE_PLATFORM,
            ['support.sessions.view'],
            null,
        );

        Passport::actingAs($manager);

        $this->getJson('/api/support/ticket-catalog/request-types')
            ->assertForbidden()
            ->assertJsonPath('missing_permissions.0', 'support.configuration.manage');
    }

    #[Test]
    public function tenant_admin_cannot_list_ops_catalog_but_can_use_tenant_lookups(): void
    {
        $this->asTenantAdmin($this->tenant);

        $this->getJson('/api/support/ticket-catalog/request-types')->assertForbidden();

        Passport::actingAs($this->tenantUser);
        $this->getJson('/api/tenant/support/lookups')
            ->assertOk()
            ->assertJsonStructure(['data' => ['request_types']]);
    }

    private function seedSupportConfigurationPermission(): void
    {
        Permission::query()->firstOrCreate(
            ['name' => 'support.configuration.manage'],
            [
                'display_name' => 'Manage support configuration',
                'module' => 'SupportAccess',
                'category' => 'support',
                'scope' => Permission::SCOPE_PLATFORM,
                'active' => 1,
                'tenant_id' => null,
                'is_custom' => false,
            ]
        );
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function makePlatformOperator(
        string $roleName,
        int $level,
        string $roleType,
        array $permissionNames,
        ?int $tenantId,
    ): User {
        $role = Role::query()->firstOrCreate(
            [
                'name' => $roleName,
                'tenant_id' => null,
            ],
            [
                'description' => $roleName.' catalog test role',
                'level' => $level,
                'active' => 1,
                'is_custom' => false,
                'role_type' => $roleType,
            ]
        );

        $permissionIds = [];
        foreach ($permissionNames as $name) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'module' => 'SupportAccess',
                    'category' => 'support',
                    'scope' => Permission::SCOPE_PLATFORM,
                    'active' => 1,
                    'tenant_id' => null,
                    'is_custom' => false,
                ]
            );
            $permissionIds[] = $permission->id;
        }
        $role->permissions()->syncWithoutDetaching($permissionIds);

        $user = User::factory()->create([
            'tenant_id' => $tenantId,
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);
        if (method_exists($user, 'clearPermissionsCache')) {
            $user->clearPermissionsCache();
        }
        if (method_exists($user, 'clearRequestPermissionCache')) {
            $user->clearRequestPermissionCache();
        }

        return $user->fresh();
    }

    /**
     * @param  list<string>  $names
     */
    private function grantTenantPermissions(User $user, array $names): void
    {
        $ids = [];
        foreach ($names as $name) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'module' => 'SupportTickets',
                    'category' => 'support',
                    'scope' => Permission::SCOPE_TENANT,
                    'active' => 1,
                    'tenant_id' => null,
                    'is_custom' => false,
                ]
            );
            $ids[] = $permission->id;
        }

        $user->permissions()->syncWithoutDetaching($ids);
        if (method_exists($user, 'clearPermissionsCache')) {
            $user->clearPermissionsCache();
        }
    }
}
