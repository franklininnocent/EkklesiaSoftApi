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

class MassScheduleRegularDraftPreviewApiTest extends TestCase
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
    public function regular_draft_preview_and_apply_keeps_friday_temporary_mass(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);
        Passport::actingAs($user);

        $regularId = $this->getJson('/api/tenant/mass-intentions/schedules/regular')->json('data.schedule.id');
        $this->applyRegular($regularId, [
            ['weekday' => 5, 'celebrated_at' => '09:00', 'place_source' => 'unset', 'celebrant_source' => 'unset'],
            ['weekday' => 0, 'celebrated_at' => '09:00', 'place_source' => 'unset', 'celebrant_source' => 'unset'],
        ]);

        $tempId = $this->postJson('/api/tenant/mass-intentions/schedules/temporaries', [
            'name' => 'Friday evening',
            'coverage_mode' => 'selected_weekdays',
            'selected_weekdays' => [5],
        ])->assertCreated()->json('data.schedule.id');

        $this->putJson("/api/tenant/mass-intentions/schedules/{$tempId}/draft", [
            'slots' => [
                ['weekday' => 5, 'celebrated_at' => '18:00', 'place_source' => 'unset', 'celebrant_source' => 'unset'],
            ],
        ])->assertOk();

        $tempPreview = $this->postJson("/api/tenant/mass-intentions/schedules/{$tempId}/preview", [
            'apply_from' => '2026-06-01',
            'effective_to' => '2026-06-30',
        ])->assertOk()->json('data');

        $this->postJson("/api/tenant/mass-intentions/schedules/{$tempId}/apply", [
            'apply_from' => '2026-06-01',
            'effective_to' => '2026-06-30',
            'fingerprint' => $tempPreview['fingerprint'],
        ])->assertOk();

        $this->putJson("/api/tenant/mass-intentions/schedules/{$regularId}/draft", [
            'slots' => [
                ['weekday' => 5, 'celebrated_at' => '09:00', 'place_source' => 'unset', 'celebrant_source' => 'unset'],
                ['weekday' => 0, 'celebrated_at' => '10:00', 'place_source' => 'unset', 'celebrant_source' => 'unset'],
            ],
        ])->assertOk();

        $preview = $this->postJson("/api/tenant/mass-intentions/schedules/{$regularId}/preview", [
            'apply_from' => '2026-06-01',
            'until' => '2026-06-30',
        ])->assertOk()->json('data');

        $this->postJson("/api/tenant/mass-intentions/schedules/{$regularId}/apply", [
            'apply_from' => '2026-06-01',
            'until' => '2026-06-30',
            'fingerprint' => $preview['fingerprint'],
        ])->assertOk();

        $friday = MassCelebration::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('celebrated_on', '2026-06-05')
            ->where('generation_status', 'active')
            ->first();

        $this->assertNotNull($friday);
        $this->assertSame(MassCelebrationOrigin::TEMPORARY, $friday->origin);
        $this->assertSame('18:00', substr((string) $friday->celebrated_at, 0, 5));

        $sunday = MassCelebration::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('celebrated_on', '2026-06-07')
            ->where('generation_status', 'active')
            ->first();

        $this->assertNotNull($sunday);
        $this->assertSame('10:00', substr((string) $sunday->celebrated_at, 0, 5));
    }

    /**
     * @param  list<array<string, mixed>>  $slots
     */
    private function applyRegular(string $scheduleId, array $slots): void
    {
        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", ['slots' => $slots])->assertOk();
        $preview = $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/preview", [
            'apply_from' => '2026-06-01',
            'until' => '2026-06-30',
        ])->assertOk()->json('data');
        $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/apply", [
            'apply_from' => '2026-06-01',
            'until' => '2026-06-30',
            'fingerprint' => $preview['fingerprint'],
        ])->assertOk();
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
