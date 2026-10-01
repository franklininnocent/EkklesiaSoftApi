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

class MassDayOverrideSecurityApiTest extends TestCase
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
    public function tenant_cannot_apply_another_tenants_day_override(): void
    {
        $tenantA = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $tenantB = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $userA = User::factory()->create(['tenant_id' => $tenantA->id, 'active' => 1]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id, 'active' => 1]);
        $this->grantPermissions($userA, ['mass.intentions.view', 'mass.intentions.schedule']);
        $this->grantPermissions($userB, ['mass.intentions.view', 'mass.intentions.schedule']);

        Passport::actingAs($userA);
        $overrideId = $this->postJson('/api/tenant/mass-intentions/day-overrides', [
            'override_on' => '2026-06-07',
            'mode' => 'replace',
            'slots' => [['celebrated_at' => '11:00']],
        ])->assertCreated()
            ->json('data.id');

        $preview = $this->postJson("/api/tenant/mass-intentions/day-overrides/{$overrideId}/preview")
            ->assertOk()
            ->json('data');

        Passport::actingAs($userB);
        $this->postJson("/api/tenant/mass-intentions/day-overrides/{$overrideId}/apply", [
            'fingerprint' => $preview['fingerprint'],
        ])->assertNotFound();
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
