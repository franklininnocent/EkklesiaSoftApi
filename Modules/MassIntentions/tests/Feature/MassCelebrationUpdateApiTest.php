<?php

namespace Modules\MassIntentions\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Modules\MassIntentions\Tests\Support\SkipsLegacyMassObligationWorkflow;
use Tests\TestCase;

class MassCelebrationUpdateApiTest extends TestCase
{
    use SkipsLegacyMassObligationWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function scheduled_mass_can_be_updated(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);

        Passport::actingAs($user);

        $celebration = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => now()->toDateString(),
            'status' => 'scheduled',
            'created_by_user_id' => $user->id,
        ]);

        $this->putJson("/api/tenant/mass-intentions/celebrations/{$celebration->id}", [
            'celebrated_on' => now()->addDay()->toDateString(),
            'celebrated_at' => '09:30',
            'place' => 'Chapel',
            'celebrant_name' => 'Fr. Smith',
        ])->assertOk()
            ->assertJsonPath('data.place', 'Chapel')
            ->assertJsonPath('data.celebrant_name', 'Fr. Smith');
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
