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

class MassIntentionWithdrawApiTest extends TestCase
{
    use SkipsLegacyMassObligationWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function withdraw_is_allowed_before_accept(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions']]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create']);

        $request = MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::PENDING_REVIEW,
            'beneficiary_name' => 'Sam',
            'intention_text' => 'Thanks',
            'created_by_user_id' => $user->id,
        ]);

        Passport::actingAs($user);

        $this->postJson("/api/tenant/mass-intentions/requests/{$request->id}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.status', MassIntentionStatus::WITHDRAWN);
    }

    #[Test]
    public function withdraw_is_rejected_after_accept(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions']]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create']);

        $request = MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::ACCEPTED,
            'beneficiary_name' => 'Sam',
            'intention_text' => 'Thanks',
            'created_by_user_id' => $user->id,
        ]);

        Passport::actingAs($user);

        $this->postJson("/api/tenant/mass-intentions/requests/{$request->id}/withdraw")
            ->assertUnprocessable();
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
