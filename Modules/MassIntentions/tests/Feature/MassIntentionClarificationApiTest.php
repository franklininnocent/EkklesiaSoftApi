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

class MassIntentionClarificationApiTest extends TestCase
{
    use SkipsLegacyMassObligationWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function reviewer_can_send_intention_back_for_details(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions']]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.review']);

        $request = MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::PENDING_REVIEW,
            'beneficiary_name' => 'John',
            'intention_text' => 'Repose',
            'mass_count_requested' => 1,
            'created_by_user_id' => $user->id,
        ]);

        Passport::actingAs($user);

        $response = $this->postJson("/api/tenant/mass-intentions/requests/{$request->id}/request-clarification", [
            'message' => 'Which spelling of the name should we use?',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', MassIntentionStatus::AWAITING_CLARIFICATION);

        $this->assertStringContainsString('Which spelling', $request->fresh()->notes ?? '');
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
