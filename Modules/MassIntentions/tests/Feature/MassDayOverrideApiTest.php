<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Support\MassCelebrationOrigin;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassDayOverrideApiTest extends TestCase
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
    public function replace_special_day_swaps_sunday_mass_time(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);

        Passport::actingAs($user);

        $scheduleId = $this->seedRegularSundayNineAm($tenant->id);

        $this->postJson('/api/tenant/mass-intentions/schedules/'.$scheduleId.'/apply', [
            'apply_from' => '2026-06-01',
            'fingerprint' => $this->previewFingerprint($scheduleId),
            'until' => '2026-06-14',
        ])->assertOk();

        $sunday = '2026-06-07';
        $saved = $this->postJson('/api/tenant/mass-intentions/day-overrides', [
            'override_on' => $sunday,
            'mode' => 'replace',
            'closes_regular_masses' => false,
            'label' => 'Feast day',
            'slots' => [
                ['celebrated_at' => '11:00', 'place' => 'Main Church'],
            ],
        ])->assertCreated()
            ->json('data');

        $overrideId = $saved['id'];
        $preview = $this->postJson('/api/tenant/mass-intentions/day-overrides/'.$overrideId.'/preview')
            ->assertOk()
            ->json('data');

        $this->postJson('/api/tenant/mass-intentions/day-overrides/'.$overrideId.'/apply', [
            'fingerprint' => $preview['fingerprint'],
        ])->assertOk();

        $regular = MassCelebration::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('celebrated_on', $sunday)
            ->where('origin', MassCelebrationOrigin::REGULAR)
            ->where('generation_status', 'active')
            ->first();
        $this->assertNull($regular);

        $special = MassCelebration::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('celebrated_on', $sunday)
            ->where('origin', MassCelebrationOrigin::SPECIAL_DAY)
            ->first();
        $this->assertNotNull($special);
        $this->assertSame('11:00', substr((string) $special->celebrated_at, 0, 5));
    }

    #[Test]
    public function supplement_rejects_duplicate_regular_slot(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);

        Passport::actingAs($user);

        $scheduleId = $this->seedRegularSundayNineAm($tenant->id);
        $this->postJson('/api/tenant/mass-intentions/schedules/'.$scheduleId.'/apply', [
            'apply_from' => '2026-06-01',
            'fingerprint' => $this->previewFingerprint($scheduleId),
            'until' => '2026-06-14',
        ])->assertOk();

        $this->postJson('/api/tenant/mass-intentions/day-overrides', [
            'override_on' => '2026-06-07',
            'mode' => 'supplement',
            'slots' => [
                ['celebrated_at' => '09:00', 'place' => 'Main Church'],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['slots']);
    }

    private function seedRegularSundayNineAm(int $tenantId): string
    {
        $bundle = $this->getJson('/api/tenant/mass-intentions/schedules/regular')
            ->assertOk()
            ->json('data');
        $scheduleId = $bundle['schedule']['id'];

        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", [
            'default_place' => 'Main Church',
            'slots' => [
                [
                    'weekday' => 0,
                    'celebrated_at' => '09:00',
                    'place_source' => 'inherit',
                    'celebrant_source' => 'unset',
                ],
            ],
        ])->assertOk();

        return $scheduleId;
    }

    private function previewFingerprint(string $scheduleId): string
    {
        return (string) $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/preview", [
            'apply_from' => '2026-06-01',
            'until' => '2026-06-14',
        ])->assertOk()
            ->json('data.fingerprint');
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function grantPermissions(User $user, array $permissionNames): void
    {
        $ids = Permission::query()->whereIn('name', $permissionNames)->pluck('id');
        $user->permissions()->syncWithoutDetaching($ids);
    }
}
