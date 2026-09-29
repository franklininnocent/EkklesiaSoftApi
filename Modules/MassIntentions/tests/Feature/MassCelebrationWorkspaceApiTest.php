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

class MassCelebrationWorkspaceApiTest extends TestCase
{
    use SkipsLegacyMassObligationWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function future_mass_cannot_be_marked_said_yet(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions']]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);

        $celebration = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => now()->addWeek()->toDateString(),
            'status' => 'scheduled',
            'created_by_user_id' => $user->id,
        ]);

        Passport::actingAs($user);

        $response = $this->getJson("/api/tenant/mass-intentions/celebrations/{$celebration->id}/workspace");

        $response->assertOk()
            ->assertJsonPath('data.can_mark_said', false);
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
