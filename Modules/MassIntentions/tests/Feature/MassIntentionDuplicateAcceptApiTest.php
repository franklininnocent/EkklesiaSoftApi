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

class MassIntentionDuplicateAcceptApiTest extends TestCase
{
    use SkipsLegacyMassObligationWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function accept_blocks_duplicate_until_warning_acknowledged(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.review']);

        $day = now()->addDay()->toDateString();

        MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::ACCEPTED,
            'beneficiary_name' => 'Maria Santos',
            'intention_text' => 'Health',
            'requested_date' => $day,
            'mass_count_accepted' => 1,
            'created_by_user_id' => $user->id,
        ]);

        $pending = MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::PENDING_REVIEW,
            'beneficiary_name' => 'Maria Santos',
            'intention_text' => 'Thanksgiving',
            'requested_date' => $day,
            'mass_count_requested' => 1,
            'created_by_user_id' => $user->id,
        ]);

        Passport::actingAs($user);

        $this->postJson("/api/tenant/mass-intentions/requests/{$pending->id}/accept", [
            'mass_count' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['duplicate_warning']);

        $this->postJson("/api/tenant/mass-intentions/requests/{$pending->id}/accept", [
            'mass_count' => 1,
            'duplicate_warning_acknowledged' => true,
        ])->assertOk()
            ->assertJsonPath('data.status', MassIntentionStatus::ACCEPTED);
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
