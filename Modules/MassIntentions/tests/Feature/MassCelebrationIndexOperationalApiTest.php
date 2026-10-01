<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Support\MassGenerationStatus;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassCelebrationIndexOperationalApiTest extends TestCase
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
    public function default_list_excludes_cancelled_and_suppressed(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);
        Passport::actingAs($user);

        $active = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => '2026-06-12',
            'celebrated_at' => '09:00',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);

        MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => '2026-06-12',
            'celebrated_at' => '11:00',
            'status' => 'cancelled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);

        MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => '2026-06-12',
            'celebrated_at' => '10:00',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::SUPPRESSED,
            'created_by_user_id' => $user->id,
        ]);

        $ids = collect(
            $this->getJson('/api/tenant/mass-intentions/celebrations?from=2026-06-12&to=2026-06-12&per_page=50')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertSame([$active->id], $ids);
    }

    #[Test]
    public function include_cancelled_shows_cancelled_masses(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);
        Passport::actingAs($user);

        MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => '2026-06-12',
            'celebrated_at' => '11:00',
            'status' => 'cancelled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);

        $this->getJson('/api/tenant/mass-intentions/celebrations?from=2026-06-12&to=2026-06-12&include_cancelled=1&per_page=50')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'cancelled');
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
