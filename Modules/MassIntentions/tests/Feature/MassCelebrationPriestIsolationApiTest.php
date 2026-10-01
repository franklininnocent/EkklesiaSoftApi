<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Support\MassGenerationStatus;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassCelebrationPriestIsolationApiTest extends TestCase
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
    public function priest_change_on_one_sunday_does_not_change_the_next(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.schedule']);
        Passport::actingAs($user);

        $slotId = '550e8400-e29b-41d4-a716-446655440099';

        $june7 = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'slot_id' => $slotId,
            'celebrated_on' => '2026-06-07',
            'celebrated_at' => '09:00',
            'celebrant_name' => 'Fr. Alpha',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);

        $june14 = MassCelebration::query()->create([
            'tenant_id' => $tenant->id,
            'slot_id' => $slotId,
            'celebrated_on' => '2026-06-14',
            'celebrated_at' => '09:00',
            'celebrant_name' => 'Fr. Alpha',
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);

        $this->putJson("/api/tenant/mass-intentions/celebrations/{$june7->id}", [
            'celebrant_name' => 'Fr. Beta',
        ])->assertOk();

        $this->assertSame('Fr. Beta', MassCelebration::query()->findOrFail($june7->id)->celebrant_name);
        $this->assertSame('Fr. Alpha', MassCelebration::query()->findOrFail($june14->id)->celebrant_name);
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
