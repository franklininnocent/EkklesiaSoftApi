<?php

namespace Modules\MassIntentions\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Support\MassGenerationStatus;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassCelebrationScheduledUpdateApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function scheduled_occurrence_allows_place_and_priest_only(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);

        Passport::actingAs($user);

        $celebration = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'slot_id' => '550e8400-e29b-41d4-a716-446655440001',
            'celebrated_on' => now()->addWeek()->toDateString(),
            'celebrated_at' => '09:00',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);

        $this->putJson("/api/tenant/mass-intentions/celebrations/{$celebration->id}", [
            'place' => 'Chapel',
            'celebrant_name' => 'Fr. Smith',
        ])->assertOk()
            ->assertJsonPath('data.place', 'Chapel');

        $this->putJson("/api/tenant/mass-intentions/celebrations/{$celebration->id}", [
            'celebrated_on' => now()->addWeeks(2)->toDateString(),
            'celebrated_at' => '10:00',
        ])->assertStatus(422);
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
