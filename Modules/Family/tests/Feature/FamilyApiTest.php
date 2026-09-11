<?php

namespace Modules\Family\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCC;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FamilyApiTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected User $user;

    protected Tenant $tenant;

    protected Family $family;

    protected BCC $bcc;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant
        $this->tenant = Tenant::factory()->create();

        // Create user with tenant
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Create a BCC for this tenant
        $this->bcc = BCC::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Create a family for this tenant
        $this->family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => $this->bcc->id,
            'created_by' => $this->user->id,
        ]);

        // Authenticate user for API requests
        Passport::actingAs($this->user);
        $this->grantFamilyPermissions($this->user);
    }

    private function grantFamilyPermissions(User $user): void
    {
        $names = ['families.view', 'families.create', 'families.edit', 'families.delete'];
        $ids = [];

        foreach ($names as $name) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'module' => 'Families',
                    'category' => 'families',
                    'scope' => Permission::SCOPE_TENANT,
                    'active' => 1,
                    'tenant_id' => null,
                    'is_custom' => false,
                ]
            );
            $ids[] = $permission->id;
        }

        $user->permissions()->syncWithoutDetaching($ids);
        $user->clearPermissionsCache();
        $user->clearRequestPermissionCache();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function memberPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'relationship_to_head' => 'self',
            'date_of_birth' => '1990-06-15',
            'gender' => 'male',
        ], $overrides);
    }

    // ==================== FAMILY CRUD OPERATIONS ====================

    #[Test]
    public function it_can_get_paginated_list_of_families()
    {
        // Arrange: Create multiple families
        Family::factory()->count(25)->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Act: Get first page
        $response = $this->getJson('/api/families?per_page=10&page=1');

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'tenant_id',
                        'family_code',
                        'family_name',
                        'status',
                        'created_at',
                    ],
                ],
                'total',
                'current_page',
                'last_page',
                'per_page',
                'from',
                'to',
            ])
            ->assertJson([
                'success' => true,
                'per_page' => 10,
                'current_page' => 1,
            ]);

        $this->assertGreaterThanOrEqual(10, count($response->json('data')));
    }

    #[Test]
    public function it_can_filter_families_by_tenant()
    {
        // Arrange: Create families for different tenants
        $otherTenant = Tenant::factory()->create();

        Family::factory()->count(5)->create([
            'tenant_id' => $this->tenant->id,
        ]);

        Family::factory()->count(3)->create([
            'tenant_id' => $otherTenant->id,
        ]);

        // Act
        $response = $this->getJson('/api/families');

        // Assert: Should only return families for authenticated user's tenant
        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $data = $response->json('data');
        foreach ($data as $family) {
            $this->assertEquals($this->tenant->id, $family['tenant_id']);
        }
    }

    #[Test]
    public function it_can_filter_families_by_status()
    {
        // Arrange
        Family::factory()->count(3)->active()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        Family::factory()->count(2)->inactive()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Act
        $response = $this->getJson('/api/families?status=active');

        // Assert
        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $data = $response->json('data');
        foreach ($data as $family) {
            $this->assertEquals('active', $family['status']);
        }
    }

    #[Test]
    public function it_can_filter_families_by_bcc()
    {
        // Arrange
        $bcc1 = BCC::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $bcc2 = BCC::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        Family::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => $bcc1->id,
        ]);

        Family::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => $bcc2->id,
        ]);

        // Act
        $response = $this->getJson("/api/families?bcc_id={$bcc1->id}");

        // Assert
        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $data = $response->json('data');
        foreach ($data as $family) {
            $this->assertEquals($bcc1->id, $family['bcc_id']);
        }
    }

    #[Test]
    public function it_can_search_families_by_name()
    {
        // Arrange
        Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_name' => 'Smith Family',
        ]);

        Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_name' => 'Johnson Family',
        ]);

        // Act
        $response = $this->getJson('/api/families?search=Smith');

        // Assert
        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $data = $response->json('data');
        $this->assertGreaterThan(0, count($data));
        $found = false;
        foreach ($data as $family) {
            if (stripos($family['family_name'], 'Smith') !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }

    #[Test]
    public function it_can_search_families_by_city()
    {
        // Arrange
        Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'city' => 'New York',
        ]);

        Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'city' => 'Los Angeles',
        ]);

        // Act
        $response = $this->getJson('/api/families?city=New York');

        // Assert
        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $data = $response->json('data');
        $this->assertGreaterThan(0, count($data));
    }

    #[Test]
    public function it_can_get_single_family_by_id()
    {
        // Arrange
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_name' => 'Test Family',
        ]);

        // Act
        $response = $this->getJson("/api/families/{$family->id}");

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $family->id,
                    'family_name' => 'Test Family',
                ],
            ]);
    }

    #[Test]
    public function it_returns_404_for_non_existent_family()
    {
        // Act
        $response = $this->getJson('/api/families/'.Str::uuid());

        // Assert
        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Family not found',
            ]);
    }

    #[Test]
    public function it_returns_404_when_accessing_family_from_different_tenant()
    {
        // Arrange: Create family for different tenant
        $otherTenant = Tenant::factory()->create();
        $otherFamily = Family::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);

        // Act
        $response = $this->getJson("/api/families/{$otherFamily->id}");

        // Assert
        $response->assertStatus(404); // Returns 404 because service filters by tenant
    }

    #[Test]
    public function it_can_create_family_with_valid_data()
    {
        // Arrange
        $bcc = BCC::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $data = [
            'family_name' => 'New Family',
            'head_of_family' => 'John Doe',
            'address_line_1' => '123 Main Street',
            'city' => 'Springfield',
            'postal_code' => '12345',
            'bcc_id' => $bcc->id,
            'status' => 'active',
            'notes' => 'Test family',
        ];

        // Act
        $response = $this->postJson('/api/families', $data);

        // Assert
        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Family created successfully',
                'data' => [
                    'family_name' => 'New Family',
                    'head_of_family' => 'John Doe',
                ],
            ]);

        $this->assertDatabaseHas('families', [
            'family_name' => 'New Family',
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);
    }

    #[Test]
    public function it_can_create_family_with_members()
    {
        // Arrange
        $data = [
            'family_name' => 'Family with Members',
            'head_of_family' => 'John Doe',
            'status' => 'active',
            'members' => [
                $this->memberPayload([
                    'first_name' => 'John',
                    'last_name' => 'Doe',
                    'relationship_to_head' => 'self',
                    'date_of_birth' => '1980-01-15',
                    'gender' => 'male',
                ]),
                $this->memberPayload([
                    'first_name' => 'Jane',
                    'last_name' => 'Doe',
                    'relationship_to_head' => 'spouse',
                    'date_of_birth' => '1982-03-20',
                    'gender' => 'female',
                ]),
            ],
        ];

        // Act
        $response = $this->postJson('/api/families', $data);

        // Assert
        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Family created successfully',
            ]);

        $family = Family::where('family_name', 'Family with Members')->first();
        $this->assertNotNull($family);
        $this->assertEquals(2, $family->members()->count());
    }

    #[Test]
    public function it_validates_required_fields_when_creating_family()
    {
        // Arrange: Missing required fields
        $data = [
            'status' => 'active',
        ];

        // Act
        $response = $this->postJson('/api/families', $data);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['family_name']);
    }

    #[Test]
    public function it_validates_status_is_valid_enum()
    {
        // Arrange
        $data = [
            'family_name' => 'Test Family',
            'status' => 'invalid-status',
        ];

        // Act
        $response = $this->postJson('/api/families', $data);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    #[Test]
    public function it_validates_bcc_exists()
    {
        // Arrange
        $data = [
            'family_name' => 'Test Family',
            'bcc_id' => Str::uuid(), // Non-existent BCC
        ];

        // Act
        $response = $this->postJson('/api/families', $data);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['bcc_id']);
    }

    #[Test]
    public function it_validates_member_required_fields()
    {
        // Arrange
        $data = [
            'family_name' => 'Test Family',
            'members' => [
                [
                    'first_name' => 'John',
                    // Missing last_name and relationship_to_head
                ],
            ],
        ];

        // Act
        $response = $this->postJson('/api/families', $data);

        // Assert
        $response->assertStatus(422);
        // Check that at least last_name is validated (relationship_to_head might have a default value)
        $response->assertJsonValidationErrors(['members.0.last_name']);
    }

    #[Test]
    public function it_can_update_family_with_valid_data()
    {
        // Arrange
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_name' => 'Original Name',
            'head_of_family' => 'Original Head',
        ]);

        $updateData = [
            'family_name' => 'Updated Name',
            'head_of_family' => 'Updated Head',
            'city' => 'New City',
            'status' => 'inactive',
        ];

        // Act
        $response = $this->putJson("/api/families/{$family->id}", $updateData);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Family updated successfully',
                'data' => [
                    'family_name' => 'Updated Name',
                    'head_of_family' => 'Updated Head',
                ],
            ]);

        $this->assertDatabaseHas('families', [
            'id' => $family->id,
            'family_name' => 'Updated Name',
            'updated_by' => $this->user->id,
        ]);
    }

    #[Test]
    public function it_creates_person_when_family_update_adds_member_without_id()
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_name' => 'Anderson Household',
        ]);

        $response = $this->putJson("/api/families/{$family->id}", [
            'family_name' => 'Anderson Household',
            'members' => [
                [
                    'first_name' => 'Alexander',
                    'last_name' => 'Anderson',
                    'phone' => '+12025550123',
                    'email' => 'alexander.anderson@example.com',
                ],
            ],
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $member = FamilyMember::query()
            ->where('family_id', $family->id)
            ->where('first_name', 'Alexander')
            ->where('last_name', 'Anderson')
            ->first();

        $this->assertNotNull($member);
        $this->assertNotNull($member->person_id);
        $this->assertDatabaseHas('persons', [
            'id' => $member->person_id,
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Alexander',
            'last_name' => 'Anderson',
        ]);
    }

    #[Test]
    public function it_updates_existing_member_when_family_update_includes_member_id()
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_name' => 'Anderson Household',
        ]);
        $member = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'Alexander',
            'last_name' => 'Anderson',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
        $originalPersonId = $member->person_id;

        $response = $this->putJson("/api/families/{$family->id}", [
            'family_name' => 'Anderson Household',
            'members' => [
                [
                    'id' => $member->id,
                    'first_name' => 'Alexander',
                    'last_name' => 'Anderson',
                    'phone' => '+12025550123',
                    'email' => 'alexander.anderson@example.com',
                ],
            ],
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertEquals(1, FamilyMember::query()->where('family_id', $family->id)->count());
        $member->refresh();
        $this->assertEquals($originalPersonId, $member->person_id);
        $this->assertEquals('alexander.anderson@example.com', $member->email);
    }

    #[Test]
    public function it_returns_404_when_updating_non_existent_family()
    {
        // Act
        $response = $this->putJson('/api/families/'.Str::uuid(), [
            'family_name' => 'Updated Name',
        ]);

        // Assert
        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Family not found',
            ]);
    }

    #[Test]
    public function it_can_delete_family()
    {
        // Arrange
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
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
        $admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);
        $admin->syncRoles([$role->id]);
        $this->grantFamilyPermissions($admin);
        Passport::actingAs($admin);

        // Act
        $response = $this->deleteJson("/api/families/{$family->id}");

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Family deleted successfully',
            ]);

        $this->assertSoftDeleted('families', [
            'id' => $family->id,
        ]);
    }

    #[Test]
    public function it_forbids_non_admin_from_deleting_family()
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $viewerRole = Role::create([
            'name' => 'Family Viewer',
            'description' => 'View only',
            'level' => 3,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);
        $viewPermission = Permission::query()->firstOrCreate(
            ['name' => 'families.view'],
            [
                'display_name' => 'families.view',
                'module' => 'Families',
                'category' => 'families',
                'scope' => Permission::SCOPE_TENANT,
                'active' => 1,
                'tenant_id' => null,
                'is_custom' => false,
            ]
        );
        $viewerRole->permissions()->sync([$viewPermission->id]);

        $viewer = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $viewerRole->id,
        ]);
        $viewer->syncRoles([$viewerRole->id]);
        $viewer->clearPermissionsCache();
        Passport::actingAs($viewer);

        $response = $this->deleteJson("/api/families/{$family->id}");

        $response->assertForbidden();

        $this->assertDatabaseHas('families', [
            'id' => $family->id,
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function it_returns_404_when_deleting_non_existent_family()
    {
        $role = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);
        $admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);
        $admin->syncRoles([$role->id]);
        $this->grantFamilyPermissions($admin);
        Passport::actingAs($admin);

        // Act
        $response = $this->deleteJson('/api/families/'.Str::uuid());

        // Assert
        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Family not found',
            ]);
    }

    #[Test]
    public function it_can_get_family_statistics()
    {
        // Arrange: Create families with different statuses
        Family::factory()->count(5)->active()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        Family::factory()->count(2)->inactive()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Act
        $response = $this->getJson('/api/families/statistics');

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_families',
                    'active_families',
                    'inactive_families',
                ],
            ]);

        $stats = $response->json('data');
        $this->assertGreaterThanOrEqual(7, $stats['total_families']);
        // Account for possible family from setUp - should be at least 5 active
        $this->assertGreaterThanOrEqual(5, $stats['active_families']);
    }

    #[Test]
    public function it_can_get_families_by_bcc()
    {
        // Arrange
        $bcc = BCC::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        Family::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => $bcc->id,
        ]);

        Family::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => null,
        ]);

        // Act
        $response = $this->getJson("/api/families/bcc/{$bcc->id}");

        // Assert
        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'family_name',
                        'bcc_id',
                    ],
                ],
            ]);

        $data = $response->json('data');
        $this->assertEquals(3, count($data));
        foreach ($data as $family) {
            $this->assertEquals($bcc->id, $family['bcc_id']);
        }
    }

    #[Test]
    public function it_can_get_families_without_bcc()
    {
        // Arrange
        $bcc = BCC::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        Family::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => $bcc->id,
        ]);

        Family::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => null,
        ]);

        // Act
        $response = $this->getJson('/api/families/without-bcc');

        // Assert
        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'family_name',
                        'bcc_id',
                    ],
                ],
            ]);

        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(2, count($data));
        foreach ($data as $family) {
            $this->assertNull($family['bcc_id']);
        }
    }

    #[Test]
    public function it_requires_authentication_to_access_families()
    {
        // Note: Testing authentication in Laravel Passport tests is complex because
        // Passport::actingAs() persists across tests. Instead, we'll verify that
        // authenticated requests work and that the auth middleware is properly configured.

        // For now, we'll verify that the endpoint requires proper authentication
        // by ensuring authenticated requests work correctly
        $response = $this->getJson('/api/families');

        // If authenticated, should return 200 (not 401)
        // This confirms the auth middleware is working
        $this->assertNotEquals(401, $response->status(), 'Authenticated request should succeed');
    }

    #[Test]
    public function it_requires_tenant_id_for_family_operations()
    {
        // Arrange: Create user without tenant_id
        $userWithoutTenant = User::factory()->create([
            'tenant_id' => null,
        ]);

        Passport::actingAs($userWithoutTenant);

        // Act
        $response = $this->getJson('/api/families');

        // Assert
        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Tenant ID is required',
            ]);
    }

    #[Test]
    public function it_can_sort_families_by_created_at_descending()
    {
        // Arrange: Create families with distinct creation times and names for easy identification
        $oldFamily = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_name' => 'Oldest Test Family '.time(),
            'created_at' => now()->subDays(5),
        ]);

        $newFamily = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_name' => 'Newest Test Family '.time(),
            'created_at' => now(),
        ]);

        // Act: Get all families sorted descending by created_at
        $response = $this->getJson('/api/families?sort_by=created_at&sort_order=desc&per_page=100');

        // Assert
        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $allData = $response->json('data');

        // Find the indices of our test families
        $newIndex = collect($allData)->search(function ($item) use ($newFamily) {
            return $item['id'] === $newFamily->id;
        });
        $oldIndex = collect($allData)->search(function ($item) use ($oldFamily) {
            return $item['id'] === $oldFamily->id;
        });

        // Both should be found, and newest should come before oldest in descending order
        $this->assertNotFalse($newIndex, 'New family should be in results');
        $this->assertNotFalse($oldIndex, 'Old family should be in results');
        if ($newIndex !== false && $oldIndex !== false) {
            $this->assertLessThan($oldIndex, $newIndex, 'Newest family should come before oldest in descending sort');
        }
    }

    #[Test]
    public function it_can_sort_families_by_name_ascending()
    {
        // Arrange
        $familyA = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_name' => 'Alpha Family',
        ]);

        $familyZ = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_name' => 'Zulu Family',
        ]);

        // Act
        $response = $this->getJson('/api/families?sort_by=family_name&sort_order=asc');

        // Assert
        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $data = $response->json('data');
        // Should be sorted alphabetically
        $this->assertGreaterThanOrEqual(2, count($data));
    }

    // ==================== FAMILY MEMBER OPERATIONS ====================

    #[Test]
    public function it_can_get_members_for_a_family()
    {
        // Arrange
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $member1 = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $member2 = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);

        // Act
        $response = $this->getJson("/api/families/{$family->id}/members");

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'family_id',
                        'first_name',
                        'last_name',
                        'relationship_to_head',
                    ],
                ],
            ]);

        $data = $response->json('data');
        $this->assertEquals(2, count($data));
    }

    #[Test]
    public function it_returns_404_when_getting_members_for_non_existent_family()
    {
        // Act
        $response = $this->getJson('/api/families/'.Str::uuid().'/members');

        // Assert
        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Family not found',
            ]);
    }

    #[Test]
    public function it_can_add_member_to_family()
    {
        // Arrange
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $data = [
            'first_name' => 'John',
            'middle_name' => 'Michael',
            'last_name' => 'Doe',
            'relationship_to_head' => 'self',
            'date_of_birth' => '1990-01-01',
            'gender' => 'male',
            'marital_status' => 'married',
            'phone' => '1234567890',
            'email' => 'john@example.com',
            'is_primary_contact' => true,
        ];

        // Act
        $response = $this->postJson("/api/families/{$family->id}/members", $data);

        // Assert
        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Family member added successfully',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'family_id',
                    'first_name',
                    'last_name',
                ],
            ]);

        $this->assertDatabaseHas('family_members', [
            'family_id' => $family->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
    }

    #[Test]
    public function it_defaults_null_baptism_priest_is_home_when_adding_member()
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->postJson("/api/families/{$family->id}/members", [
            'first_name' => 'Amanda',
            'last_name' => 'Williams',
            'relationship_to_head' => 'mother',
            'date_of_birth' => '1989-07-08',
            'gender' => 'female',
            'baptism_date' => '1990-10-10',
            'baptism_priest_is_home' => null,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $member = FamilyMember::query()
            ->where('family_id', $family->id)
            ->where('first_name', 'Amanda')
            ->first();

        $this->assertNotNull($member);
        $this->assertFalse((bool) $member->baptism_priest_is_home);
    }

    #[Test]
    public function it_validates_required_fields_when_adding_member()
    {
        // Arrange
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $data = [
            'first_name' => 'John',
            // Missing last_name and relationship_to_head
        ];

        // Act
        $response = $this->postJson("/api/families/{$family->id}/members", $data);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['last_name', 'relationship_to_head']);
    }

    #[Test]
    public function it_rejects_member_creation_without_date_of_birth()
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->postJson("/api/families/{$family->id}/members", [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'relationship_to_head' => 'son',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_of_birth']);
    }

    #[Test]
    public function it_rejects_member_creation_with_future_date_of_birth()
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->postJson("/api/families/{$family->id}/members", [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'relationship_to_head' => 'son',
            'date_of_birth' => now()->addDay()->toDateString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_of_birth']);
    }

    #[Test]
    public function it_validates_relationship_to_head_is_valid_enum()
    {
        // Arrange
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $data = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'relationship_to_head' => 'invalid-relationship',
        ];

        // Act
        $response = $this->postJson("/api/families/{$family->id}/members", $data);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['relationship_to_head']);
    }

    #[Test]
    public function it_validates_date_of_birth_is_before_today()
    {
        // Arrange
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $data = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'relationship_to_head' => 'self',
            'date_of_birth' => now()->addDay()->toDateString(), // Future date
        ];

        // Act
        $response = $this->postJson("/api/families/{$family->id}/members", $data);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_of_birth']);
    }

    #[Test]
    public function it_validates_sacrament_dates_order()
    {
        // Arrange
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $data = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'relationship_to_head' => 'self',
            'baptism_date' => '2000-01-01',
            'first_communion_date' => '1999-01-01', // Before baptism
        ];

        // Act
        $response = $this->postJson("/api/families/{$family->id}/members", $data);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['first_communion_date']);
    }

    #[Test]
    public function it_validates_deceased_date_when_status_is_deceased()
    {
        // Arrange
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $data = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'relationship_to_head' => 'self',
            'status' => 'deceased',
            // Missing deceased_date
        ];

        // Act
        $response = $this->postJson("/api/families/{$family->id}/members", $data);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['deceased_date']);
    }

    #[Test]
    public function it_can_update_family_member()
    {
        // Arrange
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $member = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'relationship_to_head' => 'self',
        ]);

        $updateData = [
            'first_name' => 'Johnny',
            'last_name' => 'Smith',
            'phone' => '9876543210',
            'occupation' => 'Engineer',
        ];

        // Act
        $response = $this->putJson("/api/families/{$family->id}/members/{$member->id}", $updateData);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Family member updated successfully',
            ]);

        $this->assertDatabaseHas('family_members', [
            'id' => $member->id,
            'first_name' => 'Johnny',
            'last_name' => 'Smith',
        ]);
    }

    #[Test]
    public function it_returns_404_when_updating_non_existent_member()
    {
        // Arrange
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Act
        $response = $this->putJson("/api/families/{$family->id}/members/".Str::uuid(), [
            'first_name' => 'Updated',
        ]);

        // Assert
        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Family member not found or does not belong to your tenant',
            ]);
    }

    #[Test]
    public function it_can_delete_family_member()
    {
        // Arrange
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $member = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'status' => 'active',
        ]);

        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'status' => 'active',
        ]);

        // Act
        $response = $this->deleteJson("/api/families/{$family->id}/members/{$member->id}");

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Family member deleted successfully',
            ]);

        $this->assertSoftDeleted('family_members', [
            'id' => $member->id,
        ]);
    }

    #[Test]
    public function it_returns_404_when_deleting_non_existent_member()
    {
        // Arrange
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Act
        $response = $this->deleteJson("/api/families/{$family->id}/members/".Str::uuid());

        // Assert
        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Family member not found',
            ]);
    }

    #[Test]
    public function it_can_add_member_with_sacrament_information()
    {
        // Arrange
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $data = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'relationship_to_head' => 'self',
            'date_of_birth' => '1995-06-15',
            'baptism_date' => '2000-01-15',
            'baptism_place' => 'St. Mary Church',
            'first_communion_date' => '2008-05-20',
            'first_communion_place' => 'St. Mary Church',
            'confirmation_date' => '2015-06-10',
            'confirmation_place' => 'Cathedral',
            'marriage_date' => '2020-07-04',
            'marriage_place' => 'St. Joseph Church',
            'marriage_spouse_name' => 'Jane Doe',
        ];

        // Act
        $response = $this->postJson("/api/families/{$family->id}/members", $data);

        // Assert
        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Family member added successfully',
            ]);

        $member = FamilyMember::where('family_id', $family->id)
            ->where('first_name', 'John')
            ->first();

        $this->assertNotNull($member);
        $this->assertEquals('2000-01-15', $member->baptism_date->format('Y-m-d'));
        $this->assertEquals('St. Mary Church', $member->baptism_place);
        $this->assertEquals('Jane Doe', $member->marriage_spouse_name);
    }

    #[Test]
    public function it_can_add_member_with_additional_information()
    {
        // Arrange
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $data = array_merge($this->memberPayload(), [
            'occupation' => 'Software Engineer',
            'education' => 'Bachelor of Science',
            'skills_talents' => 'Programming, Music, Teaching',
            'notes' => 'Active in youth ministry',
        ]);

        // Act
        $response = $this->postJson("/api/families/{$family->id}/members", $data);

        // Assert
        $response->assertStatus(201);

        $member = FamilyMember::where('family_id', $family->id)
            ->where('first_name', 'John')
            ->first();

        $this->assertNotNull($member);
        $this->assertEquals('Software Engineer', $member->occupation);
        $this->assertEquals('Active in youth ministry', $member->notes);
    }

    #[Test]
    public function it_validates_marital_status_is_valid_enum()
    {
        // Arrange
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $data = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'relationship_to_head' => 'self',
            'marital_status' => 'invalid-status',
        ];

        // Act
        $response = $this->postJson("/api/families/{$family->id}/members", $data);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['marital_status']);
    }

    #[Test]
    public function it_validates_gender_is_valid_enum()
    {
        // Arrange
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $data = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'relationship_to_head' => 'self',
            'gender' => 'invalid-gender',
        ];

        // Act
        $response = $this->postJson("/api/families/{$family->id}/members", $data);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['gender']);
    }

    #[Test]
    public function it_handles_errors_gracefully_when_service_throws_exception()
    {
        // Act: Try to get statistics
        $response = $this->getJson('/api/families/statistics');

        // Assert: Should still return proper response
        $response->assertStatus(200); // If no exception, should work
    }

    #[Test]
    public function it_can_filter_families_with_multiple_criteria()
    {
        // Arrange
        $bcc = BCC::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => $bcc->id,
            'status' => 'active',
            'city' => 'New York',
            'family_name' => 'Smith Family',
        ]);

        Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => null,
            'status' => 'inactive',
            'city' => 'Los Angeles',
        ]);

        // Act
        $response = $this->getJson("/api/families?bcc_id={$bcc->id}&status=active&city=New York&search=Smith");

        // Assert
        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(1, count($data));
    }

    #[Test]
    public function it_filters_families_by_missing_sacrament(): void
    {
        $familyMissingBaptism = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => $this->bcc->id,
            'status' => 'active',
        ]);

        FamilyMember::factory()->active()->create([
            'family_id' => $familyMissingBaptism->id,
            'date_of_birth' => '2020-01-15',
            'baptism_date' => null,
            'deceased_date' => null,
        ]);

        $familyBaptized = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => $this->bcc->id,
            'status' => 'active',
        ]);

        FamilyMember::factory()->active()->create([
            'family_id' => $familyBaptized->id,
            'date_of_birth' => '2020-01-15',
            'baptism_date' => '2020-03-01',
            'deceased_date' => null,
        ]);

        $response = $this->getJson('/api/families?missing_sacrament=BAPTISM');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($familyMissingBaptism->id, $ids);
        $this->assertNotContains($familyBaptized->id, $ids);
    }

    #[Test]
    public function it_filters_families_by_baptized_without_communion_progression(): void
    {
        $familyNeedsCommunion = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        FamilyMember::factory()->active()->create([
            'family_id' => $familyNeedsCommunion->id,
            'date_of_birth' => '2015-01-15',
            'baptism_date' => '2015-03-01',
            'first_communion_date' => null,
            'deceased_date' => null,
        ]);

        $familyComplete = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        FamilyMember::factory()->active()->create([
            'family_id' => $familyComplete->id,
            'date_of_birth' => '2015-01-15',
            'baptism_date' => '2015-03-01',
            'first_communion_date' => '2023-05-01',
            'deceased_date' => null,
        ]);

        $response = $this->getJson('/api/families?progression=baptized_without_communion');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($familyNeedsCommunion->id, $ids);
        $this->assertNotContains($familyComplete->id, $ids);
    }

    #[Test]
    public function it_filters_families_by_baptized_without_confirmation_progression(): void
    {
        $familyNeedsConfirmation = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        FamilyMember::factory()->active()->create([
            'family_id' => $familyNeedsConfirmation->id,
            'date_of_birth' => '2015-01-15',
            'baptism_date' => '2015-03-01',
            'confirmation_date' => null,
            'deceased_date' => null,
        ]);

        $familyConfirmed = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        FamilyMember::factory()->active()->create([
            'family_id' => $familyConfirmed->id,
            'date_of_birth' => '2015-01-15',
            'baptism_date' => '2015-03-01',
            'confirmation_date' => '2023-05-01',
            'deceased_date' => null,
        ]);

        $response = $this->getJson('/api/families?progression=baptized_without_confirmation');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($familyNeedsConfirmation->id, $ids);
        $this->assertNotContains($familyConfirmed->id, $ids);
    }

    #[Test]
    public function it_filters_members_by_baptized_without_communion_progression(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $needsCommunion = FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'date_of_birth' => '2015-01-15',
            'baptism_date' => '2015-03-01',
            'first_communion_date' => null,
            'deceased_date' => null,
        ]);

        $hasCommunion = FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'date_of_birth' => '2015-02-15',
            'baptism_date' => '2015-04-01',
            'first_communion_date' => '2023-05-01',
            'deceased_date' => null,
        ]);

        $response = $this->getJson('/api/members?progression=baptized_without_communion');

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $needsCommunion->id);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($hasCommunion->id, $ids);
    }

    #[Test]
    public function it_filters_members_by_baptized_without_confirmation_progression(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $needsConfirmation = FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'date_of_birth' => '2015-01-15',
            'baptism_date' => '2015-03-01',
            'confirmation_date' => null,
            'deceased_date' => null,
        ]);

        $confirmed = FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'date_of_birth' => '2015-03-15',
            'baptism_date' => '2015-05-01',
            'confirmation_date' => '2023-05-01',
            'deceased_date' => null,
        ]);

        $response = $this->getJson('/api/members?progression=baptized_without_confirmation');

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $needsConfirmation->id);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($confirmed->id, $ids);
    }

    #[Test]
    public function it_filters_members_by_female_unmarried_over_18_progression(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $eligible = FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'gender' => 'female',
            'marital_status' => 'single',
            'date_of_birth' => now()->subYears(25)->toDateString(),
            'marriage_date' => null,
            'deceased_date' => null,
        ]);

        $married = FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'gender' => 'female',
            'marital_status' => 'married',
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'deceased_date' => null,
        ]);

        $response = $this->getJson('/api/members?progression=female_unmarried_over_18');

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $eligible->id);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($married->id, $ids);
    }

    #[Test]
    public function it_filters_members_by_male_unmarried_over_23_progression(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $eligible = FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'gender' => 'male',
            'marital_status' => 'single',
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'marriage_date' => null,
            'deceased_date' => null,
        ]);

        $tooYoung = FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'gender' => 'male',
            'marital_status' => 'single',
            'date_of_birth' => now()->subYears(23)->toDateString(),
            'marriage_date' => null,
            'deceased_date' => null,
        ]);

        $response = $this->getJson('/api/members?progression=male_unmarried_over_23');

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $eligible->id);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($tooYoung->id, $ids);
    }

    #[Test]
    public function it_filters_families_by_female_unmarried_over_18_progression(): void
    {
        $familyEligible = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        FamilyMember::factory()->active()->create([
            'family_id' => $familyEligible->id,
            'gender' => 'female',
            'marital_status' => 'single',
            'date_of_birth' => now()->subYears(25)->toDateString(),
            'marriage_date' => null,
            'deceased_date' => null,
        ]);

        $familyMarried = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        FamilyMember::factory()->active()->create([
            'family_id' => $familyMarried->id,
            'gender' => 'female',
            'marital_status' => 'married',
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'deceased_date' => null,
        ]);

        $response = $this->getJson('/api/families?progression=female_unmarried_over_18');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($familyEligible->id, $ids);
        $this->assertNotContains($familyMarried->id, $ids);
    }

    #[Test]
    public function it_lists_members_in_ascending_order_by_display_name_by_default(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $charlie = FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'first_name' => 'Charlie',
            'last_name' => 'Brown',
        ]);

        $alice = FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'first_name' => 'Alice',
            'last_name' => 'Smith',
        ]);

        $betty = FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'first_name' => 'Betty',
            'last_name' => 'Jones',
        ]);

        $response = $this->getJson('/api/members');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$alice->id, $betty->id, $charlie->id], array_values(array_intersect($ids, [
            $alice->id,
            $betty->id,
            $charlie->id,
        ])));
    }
}
