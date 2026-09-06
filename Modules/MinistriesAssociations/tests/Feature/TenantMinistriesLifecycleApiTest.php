<?php

namespace Modules\MinistriesAssociations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\MinistriesAssociations\Models\LeadershipTerm;
use Modules\MinistriesAssociations\Models\MinistriesAuditLog;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationCategory;
use Modules\MinistriesAssociations\Models\OrganizationType;
use Modules\MinistriesAssociations\Models\Position;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantMinistriesLifecycleApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private OrganizationCategory $category;

    private OrganizationType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'features' => ['ministries_associations'],
            'active' => 1,
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
            'subscription_suspended_at' => null,
        ]);

        $role = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);
        $this->admin->syncRoles([$role->id]);

        $permissionIds = [];
        foreach ([
            'ministries.view',
            'ministries.create',
            'ministries.edit',
            'ministries.delete',
            'ministries.manage_members',
            'ministries.manage_leadership',
            'ministries.configure',
        ] as $name) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => 'Test permission',
                    'module' => 'MinistriesAssociations',
                    'scope' => Permission::SCOPE_TENANT,
                    'category' => 'ministries',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            );
            $permissionIds[] = $permission->id;
        }
        $role->permissions()->syncWithoutDetaching($permissionIds);

        $this->category = OrganizationCategory::query()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'MIN',
            'name' => 'Ministry',
            'is_system' => false,
            'is_active' => true,
            'display_order' => 1,
        ]);

        $this->type = OrganizationType::query()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'PARISH',
            'name' => 'Parish Ministry',
            'is_system' => false,
            'is_active' => true,
            'display_order' => 1,
        ]);
    }

    private function authenticateAdmin(): void
    {
        Passport::actingAs($this->admin);
    }

    #[Test]
    public function organization_lifecycle_enroll_status_leadership_and_re_enroll(): void
    {
        $this->authenticateAdmin();

        $create = $this->postJson('/api/tenant/ministries/organizations', [
            'category_id' => $this->category->id,
            'type_id' => $this->type->id,
            'code' => 'CHOIR',
            'name' => 'Parish Choir',
            'status' => 'active',
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.code', 'CHOIR')
            ->assertJsonPath('data.status', 'active');

        $orgId = $create->json('data.id');
        $this->assertDatabaseHas('ma_audit_logs', [
            'tenant_id' => $this->tenant->id,
            'organization_id' => $orgId,
            'event' => 'organization.created',
        ]);

        $member = $this->createParishMember($this->tenant);

        $enroll = $this->postJson("/api/tenant/ministries/organizations/{$orgId}/members", [
            'member_source' => 'parish',
            'family_member_id' => $member->id,
            'joined_date' => '2026-01-10',
            'member_type' => 'regular',
        ]);

        $enroll->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_current', true);

        $membershipId = $enroll->json('data.id');

        $this->postJson("/api/tenant/ministries/organizations/{$orgId}/members", [
            'member_source' => 'parish',
            'family_member_id' => $member->id,
            'joined_date' => '2026-01-11',
        ])->assertStatus(422);

        $position = Position::query()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'PRES',
            'name' => 'President',
            'single_occupancy' => true,
            'is_system' => false,
            'is_active' => true,
            'display_order' => 1,
        ]);

        $assign = $this->postJson("/api/tenant/ministries/organizations/{$orgId}/leadership/assign", [
            'membership_id' => $membershipId,
            'position_id' => $position->id,
            'appointment_date' => '2026-01-15',
            'effective_from' => '2026-01-15',
        ]);

        $assign->assertCreated()
            ->assertJsonPath('data.status', 'active');

        $termId = $assign->json('data.id');

        $this->postJson("/api/tenant/ministries/organizations/{$orgId}/leadership/assign", [
            'membership_id' => $membershipId,
            'position_id' => $position->id,
            'appointment_date' => '2026-01-16',
            'effective_from' => '2026-01-16',
        ])->assertStatus(422);

        $suspend = $this->patchJson(
            "/api/tenant/ministries/organizations/{$orgId}/members/{$membershipId}/status",
            [
                'status' => 'suspended',
                'exit_reason' => 'Temporary leave',
            ]
        );

        $suspend->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.is_current', true)
            ->assertJsonPath('data.exit_date', null);

        $this->assertDatabaseHas('ma_memberships', [
            'id' => $membershipId,
            'status' => 'suspended',
            'is_current' => 1,
        ]);

        $this->assertDatabaseHas('ma_leadership_terms', [
            'id' => $termId,
            'status' => LeadershipTerm::STATUS_VACATED,
            'exit_reason' => LeadershipTerm::EXIT_REASON_MEMBERSHIP_STATUS_CHANGE,
        ]);

        $this->postJson("/api/tenant/ministries/organizations/{$orgId}/members", [
            'member_source' => 'parish',
            'family_member_id' => $member->id,
            'joined_date' => '2026-02-01',
        ])->assertStatus(422);

        $reactivate = $this->patchJson(
            "/api/tenant/ministries/organizations/{$orgId}/members/{$membershipId}/status",
            ['status' => 'active']
        );
        $reactivate->assertOk()->assertJsonPath('data.status', 'active');

        $exit = $this->patchJson(
            "/api/tenant/ministries/organizations/{$orgId}/members/{$membershipId}/status",
            [
                'status' => 'exited',
                'exit_date' => '2026-03-01',
                'exit_reason' => 'Moved out of parish',
            ]
        );

        $exit->assertOk()
            ->assertJsonPath('data.status', 'exited')
            ->assertJsonPath('data.is_current', false)
            ->assertJsonPath('data.exit_date', '2026-03-01');

        $reEnroll = $this->postJson(
            "/api/tenant/ministries/organizations/{$orgId}/members/{$membershipId}/re-enroll",
            ['joined_date' => '2026-03-15']
        );

        $reEnroll->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_current', true);

        $newMembershipId = $reEnroll->json('data.id');
        $this->assertNotSame($membershipId, $newMembershipId);

        $this->assertDatabaseHas('ma_memberships', [
            'id' => $membershipId,
            'is_current' => 0,
            'status' => 'exited',
        ]);
        $this->assertDatabaseHas('ma_memberships', [
            'id' => $newMembershipId,
            'is_current' => 1,
            'status' => 'active',
        ]);

        $this->assertGreaterThanOrEqual(
            1,
            MinistriesAuditLog::query()
                ->where('tenant_id', $this->tenant->id)
                ->where('organization_id', $orgId)
                ->where('event', 'membership.re_enrolled')
                ->count()
        );
    }

    #[Test]
    public function soft_deleted_organization_code_can_be_reused(): void
    {
        $this->authenticateAdmin();

        $first = $this->postJson('/api/tenant/ministries/organizations', [
            'category_id' => $this->category->id,
            'type_id' => $this->type->id,
            'code' => 'YOUTH',
            'name' => 'Youth Ministry',
            'status' => 'active',
        ])->assertCreated();

        $orgId = $first->json('data.id');

        $this->deleteJson("/api/tenant/ministries/organizations/{$orgId}")
            ->assertOk();

        $this->assertSoftDeleted('ma_organizations', ['id' => $orgId]);

        $this->postJson('/api/tenant/ministries/organizations', [
            'category_id' => $this->category->id,
            'type_id' => $this->type->id,
            'code' => 'YOUTH',
            'name' => 'Youth Ministry Renewed',
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('data.code', 'YOUTH');
    }

    #[Test]
    public function cannot_enroll_into_inactive_organization(): void
    {
        $this->authenticateAdmin();

        $org = $this->createOrganization(['status' => 'inactive']);
        $member = $this->createParishMember($this->tenant);

        $this->postJson("/api/tenant/ministries/organizations/{$org->id}/members", [
            'member_source' => 'parish',
            'family_member_id' => $member->id,
            'joined_date' => '2026-01-10',
        ])->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function exit_status_vacates_active_leadership(): void
    {
        $this->authenticateAdmin();

        $org = $this->createOrganization();
        $member = $this->createParishMember($this->tenant);

        $membershipId = $this->postJson("/api/tenant/ministries/organizations/{$org->id}/members", [
            'member_source' => 'parish',
            'family_member_id' => $member->id,
            'joined_date' => '2026-01-01',
        ])->assertCreated()->json('data.id');

        $position = Position::query()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SEC',
            'name' => 'Secretary',
            'single_occupancy' => true,
            'is_system' => false,
            'is_active' => true,
            'display_order' => 1,
        ]);

        $termId = $this->postJson("/api/tenant/ministries/organizations/{$org->id}/leadership/assign", [
            'membership_id' => $membershipId,
            'position_id' => $position->id,
            'appointment_date' => '2026-01-02',
            'effective_from' => '2026-01-02',
        ])->assertCreated()->json('data.id');

        $this->patchJson(
            "/api/tenant/ministries/organizations/{$org->id}/members/{$membershipId}/status",
            [
                'status' => 'resigned',
                'exit_date' => '2026-02-01',
                'exit_reason' => 'Personal reasons',
            ]
        )->assertOk();

        $this->assertDatabaseHas('ma_leadership_terms', [
            'id' => $termId,
            'status' => LeadershipTerm::STATUS_VACATED,
            'exit_reason' => LeadershipTerm::EXIT_REASON_MEMBERSHIP_STATUS_CHANGE,
        ]);
    }

    #[Test]
    public function tenant_isolation_blocks_cross_tenant_organization_access(): void
    {
        $this->authenticateAdmin();

        $orgA = $this->createOrganization(['code' => 'A1', 'name' => 'Tenant A Org']);

        $tenantB = Tenant::factory()->create([
            'features' => ['ministries_associations'],
            'active' => 1,
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
            'subscription_suspended_at' => null,
        ]);

        $roleB = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant B Admin',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $tenantB->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $userB = User::factory()->create([
            'tenant_id' => $tenantB->id,
            'role_id' => $roleB->id,
        ]);
        $userB->syncRoles([$roleB->id]);

        $permissionIds = Permission::query()
            ->whereIn('name', [
                'ministries.view',
                'ministries.create',
                'ministries.edit',
                'ministries.delete',
                'ministries.manage_members',
                'ministries.manage_leadership',
            ])
            ->pluck('id')
            ->all();
        $roleB->permissions()->syncWithoutDetaching($permissionIds);

        $categoryB = OrganizationCategory::query()->create([
            'tenant_id' => $tenantB->id,
            'code' => 'MINB',
            'name' => 'Ministry B',
            'is_system' => false,
            'is_active' => true,
            'display_order' => 1,
        ]);
        $typeB = OrganizationType::query()->create([
            'tenant_id' => $tenantB->id,
            'code' => 'PARB',
            'name' => 'Parish B',
            'is_system' => false,
            'is_active' => true,
            'display_order' => 1,
        ]);

        Passport::actingAs($userB);

        $this->getJson("/api/tenant/ministries/organizations/{$orgA->id}")
            ->assertNotFound();

        $this->putJson("/api/tenant/ministries/organizations/{$orgA->id}", [
            'category_id' => $categoryB->id,
            'type_id' => $typeB->id,
            'code' => 'HACK',
            'name' => 'Hacked',
            'status' => 'active',
        ])->assertNotFound();

        $this->deleteJson("/api/tenant/ministries/organizations/{$orgA->id}")
            ->assertNotFound();

        $list = $this->getJson('/api/tenant/ministries/organizations')->assertOk();
        $ids = collect($list->json('data'))->pluck('id')->all();
        $this->assertNotContains($orgA->id, $ids);
    }

    #[Test]
    public function user_without_manage_members_cannot_enroll(): void
    {
        $this->authenticateAdmin();

        $org = $this->createOrganization();
        $member = $this->createParishMember($this->tenant);

        $viewerRole = Role::create([
            'name' => 'Ministries Viewer',
            'description' => 'Read only',
            'level' => 5,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $viewer = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $viewerRole->id,
        ]);
        $viewer->syncRoles([$viewerRole->id]);

        $viewPermission = Permission::query()->where('name', 'ministries.view')->firstOrFail();
        $viewerRole->permissions()->syncWithoutDetaching([$viewPermission->id]);

        Passport::actingAs($viewer);

        $this->postJson("/api/tenant/ministries/organizations/{$org->id}/members", [
            'member_source' => 'parish',
            'family_member_id' => $member->id,
            'joined_date' => '2026-01-10',
        ])->assertForbidden();
    }

    #[Test]
    public function unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/tenant/ministries/organizations')
            ->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOrganization(array $overrides = []): Organization
    {
        return Organization::query()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'type_id' => $this->type->id,
            'code' => 'ORG'.uniqid(),
            'name' => 'Organization '.uniqid(),
            'status' => Organization::STATUS_ACTIVE,
            'allow_multi_role_holding' => false,
            'guests_can_hold_office' => false,
        ], $overrides));
    }

    private function createParishMember(Tenant $tenant): FamilyMember
    {
        $family = Family::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        return FamilyMember::factory()->create([
            'family_id' => $family->id,
            'status' => 'active',
        ]);
    }
}
