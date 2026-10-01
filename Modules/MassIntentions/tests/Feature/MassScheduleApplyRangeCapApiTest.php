<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Support\MassScheduleConstants;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassScheduleApplyRangeCapApiTest extends TestCase
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
    public function preview_rejects_until_beyond_max_apply_range(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);
        Passport::actingAs($user);

        $scheduleId = $this->getJson('/api/tenant/mass-intentions/schedules/regular')->json('data.schedule.id');
        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", [
            'slots' => [['weekday' => 0, 'celebrated_at' => '09:00', 'place_source' => 'unset', 'celebrant_source' => 'unset']],
        ])->assertOk();

        $applyFrom = '2026-06-01';
        $tooFar = Carbon::parse($applyFrom)->addDays(MassScheduleConstants::MAX_APPLY_RANGE_DAYS + 1)->toDateString();

        $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/preview", [
            'apply_from' => $applyFrom,
            'until' => $tooFar,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['until']);
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
