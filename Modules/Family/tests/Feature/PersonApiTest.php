<?php

namespace Modules\Family\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PersonApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->grantPersonSearchPermissions($this->user);
        Passport::actingAs($this->user);
    }

    private function grantPersonSearchPermissions(User $user): void
    {
        $permission = Permission::query()->firstOrCreate(
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
        $user->permissions()->syncWithoutDetaching([$permission->id]);
        $user->clearPermissionsCache();
        $user->clearRequestPermissionCache();
    }

    #[Test]
    public function it_allows_get_search_for_parish_persons(): void
    {
        Person::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'John',
            'last_name' => 'Thomas',
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/persons?search=john&per_page=12&unaffiliated=1');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.first_name', 'John');
    }

    #[Test]
    public function unaffiliated_search_excludes_people_already_in_a_family(): void
    {
        $unaffiliated = Person::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'John',
            'last_name' => 'Unaffiliated',
            'created_by' => $this->user->id,
        ]);

        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);
        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'John',
            'last_name' => 'Affiliated',
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/persons?search=john&per_page=12&unaffiliated=1');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($unaffiliated->id));
        $this->assertCount(1, $ids);
    }
}
