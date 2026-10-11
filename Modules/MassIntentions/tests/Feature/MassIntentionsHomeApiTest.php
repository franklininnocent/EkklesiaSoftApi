<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Models\MassIntentionAssignment;
use Modules\MassIntentions\Models\MassIntentionObligation;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Models\MassSchedule;
use Modules\MassIntentions\Support\MassGenerationStatus;
use Modules\MassIntentions\Support\MassIntentionsParishTime;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\MassIntentions\Support\MassObligationStatus;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassIntentionsHomeApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function home_requires_authentication(): void
    {
        $this->getJson('/api/tenant/mass-intentions/dashboard')->assertUnauthorized();
        $this->getJson('/api/tenant/mass-intentions/home')->assertUnauthorized();
    }

    #[Test]
    public function dashboard_returns_module_summary_for_authorized_tenant_user(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions']]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);

        Passport::actingAs($user);

        $response = $this->getJson('/api/tenant/mass-intentions/dashboard');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'queue' => [
                        'open',
                        'closed',
                    ],
                    'kpis' => [
                        'open',
                        'closed',
                        'intentions_registered_this_month',
                    ],
                    'requests' => [
                        'open',
                        'closed',
                    ],
                    'period' => [
                        'intentions_registered_this_month',
                    ],
                    'trend',
                ],
            ]);
    }

    #[Test]
    public function dashboard_counts_are_tenant_scoped(): void
    {
        $tenantA = Tenant::factory()->create(['features' => ['mass_intentions']]);
        $tenantB = Tenant::factory()->create(['features' => ['mass_intentions']]);
        $userA = User::factory()->create(['tenant_id' => $tenantA->id, 'active' => 1]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id, 'active' => 1]);
        $this->grantPermissions($userA, ['mass.intentions.view']);
        $this->grantPermissions($userB, ['mass.intentions.view']);

        MassIntentionRequest::query()->create([
            'tenant_id' => $tenantA->id,
            'status' => MassIntentionStatus::OPEN,
            'beneficiary_name' => 'Parish A',
            'intention_text' => 'For the parish',
            'requested_date' => now()->addWeek()->toDateString(),
            'created_by_user_id' => $userA->id,
        ]);

        MassIntentionRequest::query()->create([
            'tenant_id' => $tenantB->id,
            'status' => MassIntentionStatus::CLOSED,
            'beneficiary_name' => 'Parish B',
            'intention_text' => 'Repose',
            'requested_date' => now()->subWeek()->toDateString(),
            'closed_at' => now(),
            'close_source' => 'manual',
            'created_by_user_id' => $userB->id,
        ]);

        Passport::actingAs($userA);
        $forA = $this->getJson('/api/tenant/mass-intentions/dashboard')->assertOk();
        $this->assertSame(1, $forA->json('data.queue.open'));
        $this->assertSame(0, $forA->json('data.queue.closed'));

        Passport::actingAs($userB);
        $forB = $this->getJson('/api/tenant/mass-intentions/dashboard')->assertOk();
        $this->assertSame(0, $forB->json('data.queue.open'));
        $this->assertSame(1, $forB->json('data.queue.closed'));
    }

    #[Test]
    public function dashboard_is_forbidden_without_view_permission(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions']]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);

        Passport::actingAs($user);

        $this->getJson('/api/tenant/mass-intentions/dashboard')->assertForbidden();
    }

    #[Test]
    public function dashboard_and_home_return_the_same_snapshot(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions']]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);

        Passport::actingAs($user);

        $dashboard = $this->getJson('/api/tenant/mass-intentions/dashboard')->assertOk()->json('data');
        $home = $this->getJson('/api/tenant/mass-intentions/home')->assertOk()->json('data');

        $this->assertSame($home, $dashboard);
    }

    #[Test]
    public function home_includes_reconciled_kpis_and_meta(): void
    {
        Carbon::setTestNow('2026-09-29 12:00:00');
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.offerings.view']);

        Passport::actingAs($user);

        $today = DonationBusinessDate::today((int) $tenant->id);
        $week = MassIntentionsParishTime::weekBoundsContaining((int) $tenant->id, $today);

        MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::OPEN,
            'beneficiary_name' => 'No Mass',
            'intention_text' => 'Test',
            'requested_date' => $today,
            'created_by_user_id' => $user->id,
        ]);

        $pastMass = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => Carbon::parse($today)->subDay()->toDateString(),
            'celebrated_at' => '09:00',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);

        $tickRequest = MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::OPEN,
            'beneficiary_name' => 'Tick',
            'intention_text' => 'Test',
            'requested_date' => $today,
            'created_by_user_id' => $user->id,
        ]);

        $obligation = MassIntentionObligation::query()->create([
            'tenant_id' => $tenant->id,
            'request_id' => $tickRequest->id,
            'sequence' => 1,
            'status' => MassObligationStatus::SCHEDULED,
        ]);

        MassIntentionAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'obligation_id' => $obligation->id,
            'celebration_id' => $pastMass->id,
            'assigned_at' => now(),
            'assigned_by_user_id' => $user->id,
        ]);

        MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'celebrated_on' => $week['sunday'],
            'celebrated_at' => '10:00',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);

        $home = $this->getJson('/api/tenant/mass-intentions/home')->assertOk()->json('data');

        $monthStart = DonationBusinessDate::monthStart((int) $tenant->id);
        $needsTickList = $this->getJson(
            '/api/tenant/mass-intentions/celebrations?needs_tick=1&from='.$monthStart.'&to='.$today.'&per_page=100'
        )->json('total');
        $needsMassList = $this->getJson('/api/tenant/mass-intentions/requests?needs_a_mass=1')->json('total');
        $weekList = $this->getJson('/api/tenant/mass-intentions/celebrations?from='.$week['sunday'].'&to='.$week['saturday'].'&include_cancelled=1&per_page=100')->json('total');

        $this->assertSame($needsTickList, $home['kpis']['needs_a_tick']);
        $this->assertSame($needsMassList, $home['kpis']['needs_a_mass']);
        $this->assertSame($weekList, $home['kpis']['this_week_masses']);
        $this->assertArrayHasKey('upcoming_celebrations', $home);
        $this->assertArrayHasKey('generation', $home);
        $this->assertArrayHasKey('meta', $home);
        $this->assertSame($week['sunday'], $home['meta']['week_from']);
        $this->assertNotEmpty($home['trend']);
        $this->assertArrayHasKey('said', $home['trend'][count($home['trend']) - 1]);
        $this->assertArrayHasKey('offerings', $home);

        $monthFrom = $home['period']['created_from'];
        $monthTo = $home['period']['created_to'];
        $registeredList = $this->getJson('/api/tenant/mass-intentions/requests?created_from='.$monthFrom.'&created_to='.$monthTo)->json('total');
        $this->assertSame($registeredList, $home['kpis']['intentions_registered_this_month']);

        Carbon::setTestNow();
    }

    #[Test]
    public function home_trend_returns_last_four_months_newest_first(): void
    {
        Carbon::setTestNow('2026-09-30 12:00:00');
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions']]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);

        Passport::actingAs($user);

        $trend = $this->getJson('/api/tenant/mass-intentions/home')->assertOk()->json('data.trend');

        $this->assertCount(4, $trend);
        $this->assertSame('2026-09', $trend[0]['month']);
        $this->assertSame('2026-08', $trend[1]['month']);
        $this->assertSame('2026-07', $trend[2]['month']);
        $this->assertSame('2026-06', $trend[3]['month']);
        $this->assertTrue($trend[0]['is_current']);
        $this->assertFalse($trend[3]['is_current'] ?? false);

        Carbon::setTestNow();
    }

    #[Test]
    public function home_omits_offerings_without_permission(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions']]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);

        Passport::actingAs($user);

        $data = $this->getJson('/api/tenant/mass-intentions/home')->assertOk()->json('data');
        $this->assertArrayNotHasKey('offerings', $data);
    }

    #[Test]
    public function generation_attention_when_never_generated(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions']]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);

        MassSchedule::query()->create([
            'tenant_id' => $tenant->id,
            'kind' => 'regular',
            'status' => 'active',
            'name' => 'Sunday',
            'created_by_user_id' => $user->id,
        ]);

        Passport::actingAs($user);

        $generation = $this->getJson('/api/tenant/mass-intentions/home')->json('data.generation');
        $this->assertTrue($generation['attention_required']);
        $this->assertSame('never_generated', $generation['attention_reason']);
    }

    /**
     * @param  list<string>  $names
     */
    private function grantPermissions(User $user, array $names): void
    {
        $ids = [];
        foreach ($names as $name) {
            $permission = Permission::query()->where('name', $name)->firstOrFail();
            $ids[] = $permission->id;
        }

        $user->permissions()->syncWithoutDetaching($ids);
        $user->clearPermissionsCache();
        if (method_exists($user, 'clearRequestPermissionCache')) {
            $user->clearRequestPermissionCache();
        }
    }
}
