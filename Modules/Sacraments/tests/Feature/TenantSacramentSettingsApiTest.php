<?php

namespace Modules\Sacraments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentAuditLog;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Models\TenantSacramentSetting;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantSacramentSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $otherUser;

    protected Tenant $tenant;

    protected Tenant $otherTenant;

    protected Role $tenantRole;

    protected SacramentType $baptismType;

    protected SacramentType $marriageType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->tenantRole = $this->makeTenantRole($this->tenant->id, 'Administrator');
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->tenantRole->id,
        ]);
        $this->user->syncRoles([$this->tenantRole->id]);
        $this->grantPermissions($this->tenantRole, [
            'sacraments.view',
            'sacraments.create',
            'sacraments.edit',
            'sacraments.settings.view',
            'sacraments.settings.manage',
        ]);

        $this->otherTenant = Tenant::factory()->create();
        $otherRole = $this->makeTenantRole($this->otherTenant->id, 'Administrator');
        $this->otherUser = User::factory()->create([
            'tenant_id' => $this->otherTenant->id,
            'role_id' => $otherRole->id,
        ]);
        $this->otherUser->syncRoles([$otherRole->id]);
        $this->grantPermissions($otherRole, [
            'sacraments.view',
            'sacraments.create',
            'sacraments.settings.view',
            'sacraments.settings.manage',
        ]);

        $this->baptismType = SacramentType::factory()->create([
            'name' => 'Baptism',
            'code' => 'BAPTISM',
            'category' => 'initiation',
            'description' => 'The first sacrament of Christian initiation.',
            'active' => true,
            'display_order' => 1,
            'requires_minister' => false,
        ]);
        $this->marriageType = SacramentType::factory()->create([
            'name' => 'Marriage',
            'code' => 'MARRIAGE',
            'category' => 'service',
            'active' => true,
            'display_order' => 2,
            'requires_minister' => false,
        ]);

        Passport::actingAs($this->user);
    }

    #[Test]
    public function it_lists_supported_types_and_defaults_missing_rows_to_active(): void
    {
        $response = $this->getJson('/api/tenant/sacrament-settings');

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'sacrament_type_id',
                        'code',
                        'name',
                        'is_active',
                    ],
                ],
            ]);

        $rows = $response->json('data');
        $this->assertGreaterThanOrEqual(2, count($rows));
        foreach ($rows as $row) {
            $this->assertTrue($row['is_active']);
        }

        $this->assertDatabaseHas('tenant_sacrament_settings', [
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function it_deactivates_and_reactivates_without_duplicating_rows(): void
    {
        $this->getJson('/api/tenant/sacrament-settings')->assertOk();

        $deactivate = $this->patchJson(
            '/api/tenant/sacrament-settings/'.$this->marriageType->id,
            ['is_active' => false]
        );
        $deactivate->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.sacrament_type_id', $this->marriageType->id);

        $activate = $this->patchJson(
            '/api/tenant/sacrament-settings/'.$this->marriageType->id,
            ['is_active' => true]
        );
        $activate->assertOk()->assertJsonPath('data.is_active', true);

        $this->assertEquals(1, TenantSacramentSetting::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('sacrament_type_id', $this->marriageType->id)
            ->count());
    }

    #[Test]
    public function it_isolates_settings_between_tenants(): void
    {
        $this->patchJson(
            '/api/tenant/sacrament-settings/'.$this->marriageType->id,
            ['is_active' => false]
        )->assertOk();

        Passport::actingAs($this->otherUser);
        $response = $this->getJson('/api/tenant/sacrament-settings');
        $response->assertOk();

        $marriage = collect($response->json('data'))
            ->firstWhere('sacrament_type_id', $this->marriageType->id);

        $this->assertNotNull($marriage);
        $this->assertTrue($marriage['is_active']);
    }

    #[Test]
    public function it_denies_settings_manage_to_create_only_users(): void
    {
        $role = $this->makeTenantRole($this->tenant->id, 'Recorder');
        $recorder = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);
        $recorder->syncRoles([$role->id]);
        $this->grantPermissions($role, ['sacraments.view', 'sacraments.create']);

        Passport::actingAs($recorder);

        $this->getJson('/api/tenant/sacrament-settings')->assertForbidden();
        $this->patchJson(
            '/api/tenant/sacrament-settings/'.$this->baptismType->id,
            ['is_active' => false]
        )->assertForbidden();
    }

    #[Test]
    public function it_blocks_new_records_for_inactive_types_and_preserves_existing_ones(): void
    {
        $existing = Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->marriageType->id,
            'recipient_name' => 'Existing Marriage',
        ]);

        $this->patchJson(
            '/api/tenant/sacrament-settings/'.$this->marriageType->id,
            ['is_active' => false]
        )->assertOk();

        $create = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->marriageType->id,
            'recipient_name' => 'New Marriage',
            'date_administered' => '2026-01-15',
            'place_administered' => 'St. Mary Church',
        ]);
        $create->assertStatus(422)
            ->assertJsonValidationErrors(['sacrament_type_id']);

        $this->getJson('/api/sacraments/'.$existing->id)
            ->assertOk()
            ->assertJsonPath('data.recipient_name', 'Existing Marriage');

        $this->getJson('/api/sacraments')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->assertDatabaseHas('sacraments', [
            'id' => $existing->id,
            'recipient_name' => 'Existing Marriage',
        ]);

        $keepHistorical = $this->putJson('/api/sacraments/'.$existing->id, [
            'recipient_name' => 'Corrected Marriage',
            'sacrament_type_id' => $this->marriageType->id,
        ]);
        $keepHistorical->assertOk()
            ->assertJsonPath('data.recipient_name', 'Corrected Marriage');

        $this->putJson('/api/sacraments/'.$existing->id, [
            'sacrament_type_id' => $this->baptismType->id,
            'recipient_name' => 'Now Baptism',
        ])->assertOk();

        $this->putJson('/api/sacraments/'.$existing->id, [
            'sacrament_type_id' => $this->marriageType->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['sacrament_type_id']);

        $this->assertDatabaseHas('sacraments', [
            'id' => $existing->id,
            'sacrament_type_id' => $this->baptismType->id,
            'recipient_name' => 'Now Baptism',
        ]);
    }

    #[Test]
    public function it_hides_inactive_types_from_the_default_types_list(): void
    {
        $this->patchJson(
            '/api/tenant/sacrament-settings/'.$this->marriageType->id,
            ['is_active' => false]
        )->assertOk();

        $response = $this->getJson('/api/sacraments/types');
        $response->assertOk();

        $types = collect($response->json('data'));
        $this->assertNull($types->firstWhere('id', $this->marriageType->id));
        $baptism = $types->firstWhere('id', $this->baptismType->id);
        $this->assertNotNull($baptism);
        $this->assertTrue($baptism['enabled_for_tenant']);
    }

    #[Test]
    public function it_includes_inactive_types_when_explicitly_requested(): void
    {
        $this->patchJson(
            '/api/tenant/sacrament-settings/'.$this->marriageType->id,
            ['is_active' => false]
        )->assertOk();

        $response = $this->getJson('/api/sacraments/types?include_inactive=1');
        $response->assertOk();

        $types = collect($response->json('data'));
        $marriage = $types->firstWhere('id', $this->marriageType->id);
        $baptism = $types->firstWhere('id', $this->baptismType->id);

        $this->assertNotNull($marriage);
        $this->assertFalse($marriage['enabled_for_tenant']);
        $this->assertTrue($baptism['enabled_for_tenant']);
    }

    #[Test]
    public function it_writes_an_audit_row_on_deactivation(): void
    {
        $this->patchJson(
            '/api/tenant/sacrament-settings/'.$this->baptismType->id,
            ['is_active' => false]
        )->assertOk();

        $this->assertDatabaseHas('sacrament_audit_logs', [
            'tenant_id' => $this->tenant->id,
            'event' => 'sacrament_setting.deactivated',
            'target_type' => 'tenant_sacrament_setting',
            'actor_user_id' => $this->user->id,
        ]);

        $log = SacramentAuditLog::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('event', 'sacrament_setting.deactivated')
            ->first();

        $this->assertNotNull($log);
        $this->assertFalse($log->new_values['is_active']);
        $this->assertTrue($log->old_values['is_active']);
        $this->assertSame($this->baptismType->id, $log->metadata['sacrament_type_id']);
    }

    private function makeTenantRole(int $tenantId, string $name): Role
    {
        return Role::create([
            'name' => $name,
            'description' => $name,
            'level' => 1,
            'active' => 1,
            'tenant_id' => $tenantId,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);
    }

    /**
     * @param  list<string>  $names
     */
    private function grantPermissions(Role $role, array $names): void
    {
        $ids = [];
        foreach ($names as $name) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => 'Test permission',
                    'module' => 'Sacraments',
                    'scope' => Permission::SCOPE_TENANT,
                    'category' => 'sacraments',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            );
            $ids[] = $permission->id;
        }
        $role->permissions()->syncWithoutDetaching($ids);
    }
}
