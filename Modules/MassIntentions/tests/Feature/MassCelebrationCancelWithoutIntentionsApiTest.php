<?php

namespace Modules\MassIntentions\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Support\MassGenerationStatus;
use Modules\MassIntentions\Support\MassSuppressionReason;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassCelebrationCancelWithoutIntentionsApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function cancel_scheduled_mass_without_intentions_succeeds(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);

        Passport::actingAs($user);

        $celebration = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => now()->addDay()->toDateString(),
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);

        $this->postJson("/api/tenant/mass-intentions/celebrations/{$celebration->id}/cancel", [
            'reason' => 'Weather',
        ])->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $fresh = MassCelebration::query()->findOrFail($celebration->id);
        $this->assertSame('cancelled', $fresh->status);
        $this->assertNull($fresh->slot_id);
    }

    #[Test]
    public function cancel_regular_occurrence_marks_user_cancelled_suppression(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);

        Passport::actingAs($user);

        $celebration = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'slot_id' => '550e8400-e29b-41d4-a716-446655440000',
            'celebrated_on' => now()->addDay()->toDateString(),
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);

        $this->postJson("/api/tenant/mass-intentions/celebrations/{$celebration->id}/cancel", [
            'reason' => 'Priest away',
        ])->assertOk();

        $fresh = MassCelebration::query()->findOrFail($celebration->id);
        $this->assertSame(MassSuppressionReason::USER_CANCELLED, $fresh->suppression_reason);
        $this->assertSame(MassGenerationStatus::SUPPRESSED, $fresh->generation_status);
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
