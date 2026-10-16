<?php

namespace Modules\MassIntentions\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Tests\Support\CreatesMassIntentionTestPayload;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassIntentionCategoriesApiTest extends TestCase
{
    use CreatesMassIntentionTestPayload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function categories_list_seeds_canonical_defaults_for_tenant(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);
        Passport::actingAs($user);

        $this->getJson('/api/tenant/mass-intentions/categories')
            ->assertOk()
            ->assertJsonPath('success', true);

        $names = collect($this->getJson('/api/tenant/mass-intentions/categories')->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Thanksgiving'));
        $this->assertGreaterThanOrEqual(18, $names->count());
    }

    #[Test]
    public function create_category_requires_create_permission_and_rejects_duplicates(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create']);
        Passport::actingAs($user);

        $this->postJson('/api/tenant/mass-intentions/categories', ['name' => 'Custom Parish Need'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Custom Parish Need');

        $this->postJson('/api/tenant/mass-intentions/categories', ['name' => 'custom parish need'])
            ->assertStatus(422);
    }

    #[Test]
    public function create_request_requires_category_description_optional(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create']);
        Passport::actingAs($user);

        $this->postJson('/api/tenant/mass-intentions/requests', [
            'beneficiary_name' => 'Test',
            'requested_date' => now()->addWeek()->toDateString(),
        ])->assertStatus(422);

        $payload = $this->validMassIntentionRequestPayload($user, [
            'intention_description' => '',
        ]);

        $this->postJson('/api/tenant/mass-intentions/requests', $payload)
            ->assertCreated()
            ->assertJsonPath('data.intention_text', 'Thanksgiving')
            ->assertJsonPath('data.intention_description', null);

        $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'intention_description' => 'Thanksgiving for the children’s birthday.',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.intention_description', 'Thanksgiving for the children’s birthday.');
    }

    /**
     * @param  list<string>  $names
     */
    private function grantPermissions(User $user, array $names): void
    {
        $ids = [];
        foreach ($names as $name) {
            $ids[] = Permission::query()->where('name', $name)->firstOrFail()->id;
        }
        $user->permissions()->syncWithoutDetaching($ids);
        $user->clearPermissionsCache();
    }
}
