<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Services\MassOccurrenceReconciler;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('pgsql')]
class MassScheduleMaterializeConcurrencyPgsqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required for concurrent materialization test.');
        }
        $this->seed(MassIntentionsPermissionSeeder::class);
        Carbon::setTestNow('2026-06-01 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function parallel_materialize_creates_single_row_per_slot_and_date(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);
        Passport::actingAs($user);

        $scheduleId = $this->getJson('/api/tenant/mass-intentions/schedules/regular')->json('data.schedule.id');
        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", [
            'slots' => [
                [
                    'weekday' => 0,
                    'celebrated_at' => '09:00',
                    'place_source' => 'unset',
                    'celebrant_source' => 'unset',
                ],
            ],
        ])->assertOk();

        $preview = $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/preview", [
            'apply_from' => '2026-06-01',
            'until' => '2026-06-14',
        ])->json('data');

        $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/apply", [
            'apply_from' => '2026-06-01',
            'fingerprint' => $preview['fingerprint'],
            'until' => '2026-06-14',
        ])->assertOk();

        $from = Carbon::parse('2026-06-07');
        $to = Carbon::parse('2026-06-07');
        $reconciler = app(MassOccurrenceReconciler::class);
        $reconciler->materialize((int) $tenant->id, $from, $to);
        $reconciler->materialize((int) $tenant->id, $from, $to);

        $this->assertSingleSundayOccurrence((int) $tenant->id, '2026-06-07');
    }

    #[Test]
    public function concurrent_on_demand_materialize_from_two_connections_creates_one_row(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);
        Passport::actingAs($user);

        $scheduleId = $this->getJson('/api/tenant/mass-intentions/schedules/regular')->json('data.schedule.id');
        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", [
            'slots' => [
                [
                    'weekday' => 0,
                    'celebrated_at' => '09:00',
                    'place_source' => 'unset',
                    'celebrant_source' => 'unset',
                ],
            ],
        ])->assertOk();

        $preview = $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/preview", [
            'apply_from' => '2026-06-01',
            'until' => '2026-06-14',
        ])->json('data');

        $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/apply", [
            'apply_from' => '2026-06-01',
            'fingerprint' => $preview['fingerprint'],
            'until' => '2026-06-14',
        ])->assertOk();

        $processes = [
            $this->startOnDemandMaterializeProcess((int) $tenant->id, '2026-06-07', '2026-06-07'),
            $this->startOnDemandMaterializeProcess((int) $tenant->id, '2026-06-07', '2026-06-07'),
        ];

        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue(
                $process->isSuccessful(),
                $process->getErrorOutput().$process->getOutput()
            );
        }

        $this->assertSingleSundayOccurrence((int) $tenant->id, '2026-06-07');
    }

    private function startOnDemandMaterializeProcess(int $tenantId, string $from, string $to): Process
    {
        $script = base_path('Modules/MassIntentions/tests/bin/on-demand-materialize.php');
        $process = new Process(
            [PHP_BINARY, $script, (string) $tenantId, $from, $to],
            base_path(),
            [
                'MASS_TEST_NOW' => '2026-06-01 10:00:00',
            ]
        );
        $process->start();

        return $process;
    }

    private function assertSingleSundayOccurrence(int $tenantId, string $date): void
    {
        $this->assertSame(
            1,
            MassCelebration::query()
                ->where('tenant_id', $tenantId)
                ->whereDate('celebrated_on', $date)
                ->whereNotNull('slot_id')
                ->count()
        );
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
