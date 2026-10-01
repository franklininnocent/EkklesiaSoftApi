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

class MassGenerationStatusApiTest extends TestCase
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
    public function generation_status_returns_cursor_and_horizon(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view']);
        Passport::actingAs($user);

        MassGenerationCursor::query()->create([
            'tenant_id' => $tenant->id,
            'last_generated_through' => '2026-09-01',
            'last_success_at' => now(),
            'last_error' => null,
        ]);

        $data = $this->getJson('/api/tenant/mass-intentions/generation-status')
            ->assertOk()
            ->json('data');

        $this->assertSame('2026-09-01', $data['last_generated_through']);
        $this->assertIsInt($data['horizon_days']);
        $this->assertGreaterThan(0, $data['horizon_days']);
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
