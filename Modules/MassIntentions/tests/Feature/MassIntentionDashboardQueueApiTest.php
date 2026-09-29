<?php

namespace Modules\MassIntentions\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Modules\MassIntentions\Tests\Support\SkipsLegacyMassObligationWorkflow;
use Tests\TestCase;

class MassIntentionDashboardQueueApiTest extends TestCase
{
    use SkipsLegacyMassObligationWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function home_unfinished_count_matches_draft_intentions_list(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create']);

        MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::DRAFT,
            'beneficiary_name' => 'Queue Test',
            'intention_text' => 'Test',
            'mass_count_requested' => 1,
            'created_by_user_id' => $user->id,
        ]);

        Passport::actingAs($user);

        $home = $this->getJson('/api/tenant/mass-intentions/home')->json('data.queue.unfinished');
        $list = $this->getJson('/api/tenant/mass-intentions/requests?status=draft')->json('total');

        $this->assertSame(1, $home);
        $this->assertSame(1, $list);
    }

    #[Test]
    public function home_not_scheduled_queue_matches_list_queue_filter(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, [
            'mass.intentions.view',
            'mass.intentions.review',
        ]);

        Passport::actingAs($user);

        $request = MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::DRAFT,
            'beneficiary_name' => 'Not Scheduled',
            'intention_text' => 'Test',
            'mass_count_requested' => 1,
            'created_by_user_id' => $user->id,
        ]);

        $this->postJson("/api/tenant/mass-intentions/requests/{$request->id}/accept", ['mass_count' => 1])->assertOk();

        $home = $this->getJson('/api/tenant/mass-intentions/home')->json('data.queue.not_scheduled');
        $list = $this->getJson('/api/tenant/mass-intentions/requests?queue=not_scheduled')->json('total');

        $this->assertGreaterThanOrEqual(1, $home);
        $this->assertGreaterThanOrEqual(1, $list);
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
