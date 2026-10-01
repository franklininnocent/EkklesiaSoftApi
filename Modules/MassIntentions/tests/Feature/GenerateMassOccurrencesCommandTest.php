<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Models\MassGenerationCursor;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateMassOccurrencesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
        Carbon::setTestNow('2026-06-01 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function command_materializes_regular_schedule_and_updates_cursor(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);
        Passport::actingAs($user);

        $scheduleId = $this->getJson('/api/tenant/mass-intentions/schedules/regular')->json('data.schedule.id');
        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", [
            'slots' => [
                ['weekday' => 0, 'celebrated_at' => '09:00', 'place_source' => 'unset', 'celebrant_source' => 'unset'],
            ],
        ])->assertOk();
        $preview = $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/preview", [
            'apply_from' => '2026-06-01',
            'until' => '2026-06-14',
        ])->assertOk()->json('data');
        $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/apply", [
            'apply_from' => '2026-06-01',
            'fingerprint' => $preview['fingerprint'],
            'until' => '2026-06-14',
        ])->assertOk();

        $this->artisan('mass-intentions:generate-occurrences', ['--tenant' => $tenant->id])
            ->assertSuccessful();

        $count = MassCelebration::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('slot_id')
            ->count();

        $this->assertGreaterThan(0, $count);

        $cursor = MassGenerationCursor::query()->find($tenant->id);
        $this->assertNotNull($cursor);
        $this->assertNotNull($cursor->last_generated_through);
        $this->assertNull($cursor->last_error);
    }

    #[Test]
    public function command_skips_tenant_without_mass_intentions_feature(): void
    {
        $tenant = Tenant::factory()->create(['features' => [], 'currency_code' => 'USD']);

        Artisan::call('mass-intentions:generate-occurrences', ['--tenant' => $tenant->id]);

        $this->assertNull(MassGenerationCursor::query()->find($tenant->id));
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
