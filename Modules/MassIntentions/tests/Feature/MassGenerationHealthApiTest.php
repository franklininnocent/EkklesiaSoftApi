<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassGenerationCursor;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassGenerationHealthApiTest extends TestCase
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
    public function generation_status_flags_cursor_behind_attention_threshold(): void
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

        MassGenerationCursor::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'last_generated_through' => '2026-06-15',
                'last_success_at' => now(),
                'last_error' => null,
            ]
        );

        $data = $this->getJson('/api/tenant/mass-intentions/generation-status')
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['attention_required']);
        $this->assertSame('behind_horizon', $data['attention_reason']);
        $this->assertSame('2026-08-30', $data['minimum_through_date']);
    }

    #[Test]
    public function check_generation_health_command_exits_nonzero_when_behind(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);
        Passport::actingAs($user);
        $this->getJson('/api/tenant/mass-intentions/schedules/regular')->assertOk();

        MassGenerationCursor::query()->create([
            'tenant_id' => $tenant->id,
            'last_generated_through' => '2026-01-01',
            'last_success_at' => now(),
            'last_error' => null,
        ]);

        $this->artisan('mass-intentions:check-generation-health')
            ->assertFailed();
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
