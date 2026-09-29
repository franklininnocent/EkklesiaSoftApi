<?php

namespace Modules\MassIntentions\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Modules\MassIntentions\Tests\Support\SkipsLegacyMassObligationWorkflow;
use Tests\TestCase;

class MassIntentionOfficeRegistrationApiTest extends TestCase
{
    use SkipsLegacyMassObligationWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function create_always_starts_as_draft_even_when_submit_for_review_is_sent(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create']);

        Passport::actingAs($user);

        $this->postJson('/api/tenant/mass-intentions/requests', [
            'beneficiary_name' => 'Maria Santos',
            'intention_text' => 'Repose',
            'mass_count' => 1,
            'submit_for_review' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', MassIntentionStatus::DRAFT);
    }

    #[Test]
    public function update_does_not_move_request_to_pending_review(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create']);

        Passport::actingAs($user);

        $id = $this->postJson('/api/tenant/mass-intentions/requests', [
            'beneficiary_name' => 'Maria Santos',
            'intention_text' => 'Repose',
            'mass_count' => 1,
        ])->json('data.id');

        $this->putJson("/api/tenant/mass-intentions/requests/{$id}", [
            'beneficiary_name' => 'Maria Santos',
            'intention_text' => 'Repose of soul',
            'mass_count' => 1,
            'submit_for_review' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', MassIntentionStatus::DRAFT);
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
