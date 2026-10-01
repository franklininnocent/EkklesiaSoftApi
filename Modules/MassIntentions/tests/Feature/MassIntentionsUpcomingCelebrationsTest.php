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

class MassIntentionsUpcomingCelebrationsTest extends TestCase
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
    public function home_upcoming_celebrations_exclude_masses_that_already_started_today(): void
    {
        $timezone = 'Asia/Kolkata';
        Carbon::setTestNow(Carbon::parse('2026-09-30 09:06:00', $timezone));

        $tenant = Tenant::factory()->create([
            'features' => ['mass_intentions'],
            'currency_code' => 'USD',
            'settings' => ['timezone' => $timezone, 'language' => 'en', 'currency' => 'USD'],
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);

        Passport::actingAs($user);

        $pastToday = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => '2026-09-30',
            'celebrated_at' => '06:15:00',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);
        $laterToday = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => '2026-09-30',
            'celebrated_at' => '11:00:00',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);
        MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => '2026-10-01',
            'celebrated_at' => '06:00:00',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);
        MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => '2026-09-30',
            'celebrated_at' => '20:00:00',
            'status' => 'cancelled',
            'created_by_user_id' => $user->id,
        ]);

        $response = $this->getJson('/api/tenant/mass-intentions/home')->assertOk();
        $ids = collect($response->json('data.upcoming_celebrations'))->pluck('id')->all();

        $this->assertSame([$laterToday->id], array_slice($ids, 0, 1));
        $this->assertNotContains($pastToday->id, $ids);
        $this->assertCount(2, $ids);
    }

    #[Test]
    public function home_upcoming_excludes_mass_scheduled_at_exact_parish_now(): void
    {
        $timezone = 'Asia/Kolkata';
        Carbon::setTestNow(Carbon::parse('2026-09-30 11:00:00', $timezone));

        $tenant = Tenant::factory()->create([
            'features' => ['mass_intentions'],
            'settings' => ['timezone' => $timezone, 'language' => 'en', 'currency' => 'USD'],
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);

        Passport::actingAs($user);

        $atNow = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => '2026-09-30',
            'celebrated_at' => '11:00:00',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);
        $afterNow = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => '2026-09-30',
            'celebrated_at' => '11:01:00',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);

        $ids = collect($this->getJson('/api/tenant/mass-intentions/home')->json('data.upcoming_celebrations'))
            ->pluck('id')
            ->all();

        $this->assertNotContains($atNow->id, $ids);
        $this->assertSame($afterNow->id, $ids[0] ?? null);
    }

    #[Test]
    public function celebrations_index_meta_matches_home_first_upcoming_mass(): void
    {
        $timezone = 'Asia/Kolkata';
        Carbon::setTestNow(Carbon::parse('2026-09-30 09:06:00', $timezone));

        $tenant = Tenant::factory()->create([
            'features' => ['mass_intentions'],
            'settings' => ['timezone' => $timezone, 'language' => 'en', 'currency' => 'USD'],
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);

        Passport::actingAs($user);

        MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => '2026-09-30',
            'celebrated_at' => '06:15:00',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);
        $next = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => '2026-09-30',
            'celebrated_at' => '11:00:00',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);

        $homeFirst = $this->getJson('/api/tenant/mass-intentions/home')->json('data.upcoming_celebrations.0.id');
        $metaNext = $this->getJson('/api/tenant/mass-intentions/celebrations?from=2026-09-30&to=2026-09-30')
            ->json('meta.next_upcoming_celebration_id');

        $this->assertSame($next->id, $homeFirst);
        $this->assertSame($next->id, $metaNext);
    }

    #[Test]
    public function home_upcoming_is_empty_when_no_masses_remain_today_or_later(): void
    {
        $timezone = 'Asia/Kolkata';
        Carbon::setTestNow(Carbon::parse('2026-09-30 21:00:00', $timezone));

        $tenant = Tenant::factory()->create([
            'features' => ['mass_intentions'],
            'settings' => ['timezone' => $timezone, 'language' => 'en', 'currency' => 'USD'],
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);

        Passport::actingAs($user);

        MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => '2026-09-30',
            'celebrated_at' => '18:00:00',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);

        $upcoming = $this->getJson('/api/tenant/mass-intentions/home')->json('data.upcoming_celebrations');
        $this->assertSame([], $upcoming);
        $this->assertSame(0, $this->getJson('/api/tenant/mass-intentions/home')->json('data.kpis.upcoming_masses'));
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
