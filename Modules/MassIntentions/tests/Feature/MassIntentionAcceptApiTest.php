<?php

namespace Modules\MassIntentions\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\MassIntentions\Support\MassObligationStatus;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Modules\MassIntentions\Tests\Support\SkipsLegacyMassObligationWorkflow;
use Tests\TestCase;

class MassIntentionAcceptApiTest extends TestCase
{
    use SkipsLegacyMassObligationWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function accept_creates_obligations_and_offering_without_payment(): void
    {
        $tenant = Tenant::factory()->create([
            'features' => ['mass_intentions'],
            'currency_code' => 'USD',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, [
            'mass.intentions.view',
            'mass.intentions.create',
            'mass.intentions.review',
        ]);

        Passport::actingAs($user);

        $request = MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::PENDING_REVIEW,
            'beneficiary_name' => 'Maria Santos',
            'intention_text' => 'Thanksgiving',
            'mass_count_requested' => 2,
            'created_by_user_id' => $user->id,
        ]);

        $response = $this->postJson("/api/tenant/mass-intentions/requests/{$request->id}/accept", [
            'mass_count' => 2,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', MassIntentionStatus::ACCEPTED)
            ->assertJsonPath('data.said_progress.total', 2);

        $this->assertDatabaseCount('mass_intention_obligations', 2);
        $this->assertDatabaseHas('mass_intention_obligations', [
            'request_id' => $request->id,
            'sequence' => 1,
            'status' => MassObligationStatus::PENDING,
        ]);
        $this->assertDatabaseHas('mass_intention_offerings', [
            'request_id' => $request->id,
        ]);
        $this->assertDatabaseCount('mass_intention_offering_receipts', 0);
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
        if (method_exists($user, 'clearRequestPermissionCache')) {
            $user->clearRequestPermissionCache();
        }
    }
}
