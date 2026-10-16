<?php

namespace Modules\MassIntentions\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassCelebrationIndexApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function index_filters_by_search_status_and_mass_day(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);

        Passport::actingAs($user);

        MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => '2026-03-15',
            'celebrated_at' => '09:00:00',
            'place' => 'Main Church',
            'celebrant_name' => 'Fr. Alpha',
            'status' => 'scheduled',
            'created_by_user_id' => $user->id,
        ]);
        MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => '2026-03-16',
            'celebrated_at' => '11:00:00',
            'place' => 'Chapel',
            'celebrant_name' => 'Fr. Beta',
            'status' => 'cancelled',
            'created_by_user_id' => $user->id,
        ]);

        $this->getJson('/api/tenant/mass-intentions/celebrations?from=2026-03-01&to=2026-03-31&search=Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.celebrant_name', 'Fr. Alpha');

        $this->getJson('/api/tenant/mass-intentions/celebrations?from=2026-03-01&to=2026-03-31&status=cancelled')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'cancelled');

        $this->getJson('/api/tenant/mass-intentions/celebrations?from=2026-03-01&to=2026-03-31&celebrated_on=2026-03-15')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.place', 'Main Church');
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
