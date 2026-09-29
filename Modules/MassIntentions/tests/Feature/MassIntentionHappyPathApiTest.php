<?php

namespace Modules\MassIntentions\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Modules\MassIntentions\Tests\Support\SkipsLegacyMassObligationWorkflow;
use Tests\TestCase;

class MassIntentionHappyPathApiTest extends TestCase
{
    use SkipsLegacyMassObligationWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function office_flow_from_create_through_register(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, [
            'mass.intentions.view',
            'mass.intentions.create',
            'mass.intentions.review',
            'mass.intentions.schedule',
            'mass.intentions.fulfil',
            'mass.intentions.register.export',
        ]);

        Passport::actingAs($user);

        $create = $this->postJson('/api/tenant/mass-intentions/requests', [
            'beneficiary_name' => 'James Lee',
            'intention_text' => 'Thanksgiving',
            'mass_count' => 1,
        ]);
        $create->assertCreated()
            ->assertJsonPath('data.status', MassIntentionStatus::DRAFT);
        $requestId = $create->json('data.id');

        $this->postJson("/api/tenant/mass-intentions/requests/{$requestId}/accept", [
            'mass_count' => 1,
        ])->assertOk();

        $massDay = now()->toDateString();
        $celebration = $this->postJson('/api/tenant/mass-intentions/celebrations', [
            'celebrated_on' => $massDay,
            'place' => 'Main church',
        ]);
        $celebration->assertCreated();
        $celebrationId = $celebration->json('data.id');

        $this->postJson("/api/tenant/mass-intentions/celebrations/{$celebrationId}/assign-obligations", [
            'obligation_ids' => [
                $this->getJson("/api/tenant/mass-intentions/obligations/pending-schedule")
                    ->json('data.0.obligation_id'),
            ],
        ])->assertOk();

        $obligationId = $this->getJson("/api/tenant/mass-intentions/celebrations/{$celebrationId}/workspace")
            ->json('data.intentions.0.obligation_id');

        $this->postJson("/api/tenant/mass-intentions/celebrations/{$celebrationId}/confirm-said", [
            'obligation_ids' => [$obligationId],
        ])->assertOk();

        $register = $this->getJson('/api/tenant/mass-intentions/reports/canonical-register');
        $register->assertOk();
        $row = collect($register->json('data'))->firstWhere('request_id', $requestId);
        $this->assertNotNull($row);
        $this->assertSame('1 of 1', $row['progress']);
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
