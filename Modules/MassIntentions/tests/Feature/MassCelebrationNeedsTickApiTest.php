<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
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

class MassCelebrationNeedsTickApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
        Carbon::setTestNow('2026-06-10 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function needs_tick_excludes_empty_suppressed_cancelled_and_future_masses(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);
        Passport::actingAs($user);

        $base = [
            'tenant_id' => $tenant->id,
            'celebrated_on' => '2026-06-09',
            'celebrated_at' => '09:00',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ];

        MassCelebration::query()->create($base);
        MassCelebration::query()->create(array_merge($base, [
            'celebrated_on' => '2026-06-11',
        ]));
        MassCelebration::query()->create(array_merge($base, [
            'status' => 'cancelled',
        ]));
        MassCelebration::query()->create(array_merge($base, [
            'generation_status' => MassGenerationStatus::SUPPRESSED,
            'suppression_reason' => MassSuppressionReason::SCHEDULE_CHANGED,
        ]));

        $this->getJson('/api/tenant/mass-intentions/celebrations?needs_tick=1&per_page=50')
            ->assertOk()
            ->assertJsonCount(0, 'data');
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
