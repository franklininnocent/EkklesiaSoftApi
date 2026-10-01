<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassCelebrationNextUpcomingApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function index_meta_identifies_next_mass_after_current_parish_time(): void
    {
        $timezone = 'Asia/Kolkata';
        Carbon::setTestNow(Carbon::parse('2026-09-29 16:00:00', $timezone));

        $tenant = Tenant::factory()->create([
            'features' => ['mass_intentions'],
            'currency_code' => 'USD',
            'settings' => ['timezone' => $timezone, 'language' => 'en', 'currency' => 'USD'],
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);

        Passport::actingAs($user);

        $pastMorning = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => '2026-09-29',
            'celebrated_at' => '06:15:00',
            'status' => 'scheduled',
            'created_by_user_id' => $user->id,
        ]);
        $next = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => '2026-09-29',
            'celebrated_at' => '18:15:00',
            'status' => 'scheduled',
            'created_by_user_id' => $user->id,
        ]);
        MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => '2026-09-30',
            'celebrated_at' => '06:15:00',
            'status' => 'scheduled',
            'created_by_user_id' => $user->id,
        ]);
        MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => '2026-09-29',
            'celebrated_at' => '20:00:00',
            'status' => 'cancelled',
            'created_by_user_id' => $user->id,
        ]);

        $this->getJson('/api/tenant/mass-intentions/celebrations?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('meta.next_upcoming_celebration_id', $next->id)
            ->assertJsonPath('meta.parish_timezone', $timezone);

        $this->assertNotSame($pastMorning->id, $next->id);
    }

    #[Test]
    public function index_meta_next_mass_can_fall_outside_list_date_range(): void
    {
        $timezone = 'Asia/Kolkata';
        Carbon::setTestNow(Carbon::parse('2026-09-29 16:00:00', $timezone));

        $tenant = Tenant::factory()->create([
            'features' => ['mass_intentions'],
            'currency_code' => 'USD',
            'settings' => ['timezone' => $timezone, 'language' => 'en', 'currency' => 'USD'],
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);

        Passport::actingAs($user);

        $november = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => '2026-11-05',
            'celebrated_at' => '09:00:00',
            'status' => 'scheduled',
            'created_by_user_id' => $user->id,
        ]);

        $response = $this->getJson('/api/tenant/mass-intentions/celebrations?from=2026-10-01&to=2026-10-31')
            ->assertOk();

        $response->assertJsonPath('meta.next_upcoming_celebration_id', $november->id);
        $this->assertCount(0, $response->json('data'));
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
