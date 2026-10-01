<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Support\MassGenerationStatus;
use Modules\MassIntentions\Support\MassSuppressionReason;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassCelebrationSuppressedDetailApiTest extends TestCase
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
    public function show_includes_suppression_metadata_for_schedule_removed_mass(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);
        Passport::actingAs($user);

        $celebration = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'slot_id' => '550e8400-e29b-41d4-a716-446655440010',
            'celebrated_on' => '2026-06-07',
            'celebrated_at' => '09:00',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::SUPPRESSED,
            'suppression_reason' => MassSuppressionReason::SCHEDULE_CHANGED,
            'source_label' => 'Sunday 9:00 AM',
            'suppressed_at' => now(),
            'created_by_user_id' => $user->id,
        ]);

        $this->getJson("/api/tenant/mass-intentions/celebrations/{$celebration->id}")
            ->assertOk()
            ->assertJsonPath('data.generation_status', MassGenerationStatus::SUPPRESSED)
            ->assertJsonPath('data.suppression_reason', MassSuppressionReason::SCHEDULE_CHANGED)
            ->assertJsonPath('data.source_label', 'Sunday 9:00 AM');
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
