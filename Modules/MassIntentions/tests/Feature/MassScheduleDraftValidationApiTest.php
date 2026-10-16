<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassScheduleDraftValidationApiTest extends TestCase
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
    public function draft_rejects_more_than_eight_slots_on_one_weekday(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);
        Passport::actingAs($user);

        $scheduleId = $this->getJson('/api/tenant/mass-intentions/schedules/regular')->json('data.schedule.id');

        $slots = [];
        for ($i = 0; $i < 9; $i++) {
            $slots[] = [
                'weekday' => 0,
                'celebrated_at' => sprintf('%02d:00', 6 + $i),
                'place_source' => 'unset',
                'celebrant_source' => 'unset',
            ];
        }

        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", [
            'slots' => $slots,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['slots']);
    }

    #[Test]
    public function draft_rejects_duplicate_time_and_place_on_same_weekday(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);
        Passport::actingAs($user);

        $scheduleId = $this->getJson('/api/tenant/mass-intentions/schedules/regular')->json('data.schedule.id');

        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", [
            'default_place' => 'Main Church',
            'slots' => [
                [
                    'weekday' => 0,
                    'celebrated_at' => '09:00',
                    'place_source' => 'inherit',
                    'celebrant_source' => 'unset',
                ],
                [
                    'weekday' => 0,
                    'celebrated_at' => '09:00',
                    'place_source' => 'inherit',
                    'celebrant_source' => 'unset',
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['slots']);
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
