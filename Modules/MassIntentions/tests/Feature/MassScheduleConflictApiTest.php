<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Models\MassIntentionAssignment;
use Modules\MassIntentions\Models\MassIntentionObligation;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\MassIntentions\Support\MassObligationStatus;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassScheduleConflictApiTest extends TestCase
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
    public function preview_reports_conflict_when_intention_blocks_schedule_removal(): void
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

        $celebration = MassCelebration::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('celebrated_on', '2026-06-07')
            ->firstOrFail();

        $request = MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::ACCEPTED,
            'beneficiary_name' => 'Family Name',
            'intention_text' => 'Repose',
            'mass_count_accepted' => 1,
            'created_by_user_id' => $user->id,
        ]);
        $obligation = MassIntentionObligation::query()->create([
            'tenant_id' => $tenant->id,
            'request_id' => $request->id,
            'sequence' => 1,
            'status' => MassObligationStatus::SCHEDULED,
        ]);
        MassIntentionAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'obligation_id' => $obligation->id,
            'celebration_id' => $celebration->id,
            'assigned_at' => now(),
            'assigned_by_user_id' => $user->id,
        ]);

        $this->putJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/draft", ['slots' => []])->assertOk();

        $removePreview = $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/preview", [
            'apply_from' => '2026-06-01',
            'until' => '2026-06-14',
        ])->assertOk()->json('data');

        $this->assertGreaterThan(0, $removePreview['counts']['conflict']);
        $this->assertNotEmpty($removePreview['conflicts']);
        $this->assertSame($celebration->id, $removePreview['conflicts'][0]['celebration_id']);
        $this->assertSame(1, $removePreview['conflicts'][0]['intention_count']);

        $this->postJson("/api/tenant/mass-intentions/schedules/{$scheduleId}/apply", [
            'apply_from' => '2026-06-01',
            'fingerprint' => $removePreview['fingerprint'],
            'until' => '2026-06-14',
        ])->assertOk();

        $celebration->refresh();
        $this->assertSame('scheduled', $celebration->status);
        $this->assertSame('09:00', substr((string) $celebration->celebrated_at, 0, 5));
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
