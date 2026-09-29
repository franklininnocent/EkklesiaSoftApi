<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Support\MassIntentionCloseSource;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\MassIntentions\Tests\Support\CreatesMassIntentionTestPayload;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassIntentionOfficeWorkflowApiTest extends TestCase
{
    use CreatesMassIntentionTestPayload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function create_opens_intention_with_required_scheduled_date(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create']);

        Passport::actingAs($user);

        $day = Carbon::now('UTC')->addWeek()->toDateString();

        $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'requested_date' => $day,
            'mass_intention_category_id' => $this->massIntentionCategoryIdFor($user, 'FAITHFUL_DEPARTED'),
            'intention_description' => 'Repose of the soul of John.',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.status', MassIntentionStatus::OPEN)
            ->assertJsonPath('data.requested_date', $day)
            ->assertJsonPath('data.intention_text', 'For the Faithful Departed');
    }

    #[Test]
    public function accept_endpoint_is_disabled(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create', 'mass.intentions.review']);

        Passport::actingAs($user);

        $id = $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'mass_intention_category_id' => $this->massIntentionCategoryIdFor($user, 'FAITHFUL_DEPARTED'),
        ]))->json('data.id');

        $this->postJson("/api/tenant/mass-intentions/requests/{$id}/accept", ['mass_count' => 1])
            ->assertStatus(422);
    }

    #[Test]
    public function manual_close_requires_review_permission(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $creator = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($creator, ['mass.intentions.view', 'mass.intentions.create']);

        Passport::actingAs($creator);

        $id = $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($creator, [
            'mass_intention_category_id' => $this->massIntentionCategoryIdFor($creator, 'FAITHFUL_DEPARTED'),
        ]))->json('data.id');

        $this->postJson("/api/tenant/mass-intentions/requests/{$id}/close")->assertForbidden();

        $this->grantPermissions($creator, ['mass.intentions.review']);

        $this->postJson("/api/tenant/mass-intentions/requests/{$id}/close")
            ->assertOk()
            ->assertJsonPath('data.status', MassIntentionStatus::CLOSED)
            ->assertJsonPath('data.close_source', MassIntentionCloseSource::MANUAL);
    }

    #[Test]
    public function closed_intention_cannot_be_edited(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create', 'mass.intentions.review']);

        Passport::actingAs($user);

        $day = Carbon::now('UTC')->addWeek()->toDateString();
        $categoryId = $this->massIntentionCategoryIdFor($user, 'FAITHFUL_DEPARTED');
        $id = $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'requested_date' => $day,
            'mass_intention_category_id' => $categoryId,
        ]))->json('data.id');

        $this->postJson("/api/tenant/mass-intentions/requests/{$id}/close")->assertOk();

        $this->putJson("/api/tenant/mass-intentions/requests/{$id}", $this->validMassIntentionRequestPayload($user, [
            'requested_date' => $day,
            'mass_intention_category_id' => $categoryId,
            'intention_description' => 'Changed details',
        ]))->assertStatus(422);
    }

    #[Test]
    public function list_filters_by_requested_date(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create']);

        Passport::actingAs($user);

        $dayA = Carbon::now('UTC')->addDays(10)->toDateString();
        $dayB = Carbon::now('UTC')->addDays(20)->toDateString();

        $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'beneficiary_name' => 'On Day A',
            'requested_date' => $dayA,
            'mass_intention_category_id' => $this->massIntentionCategoryIdFor($user, 'THANKSGIVING'),
        ]))->assertCreated();

        $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'beneficiary_name' => 'On Day B',
            'requested_date' => $dayB,
            'mass_intention_category_id' => $this->massIntentionCategoryIdFor($user, 'THANKSGIVING'),
        ]))->assertCreated();

        $this->getJson('/api/tenant/mass-intentions/requests?requested_date='.$dayA)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.beneficiary_name', 'On Day A');

        $this->getJson('/api/tenant/mass-intentions/requests?requested_date='.$dayB)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.beneficiary_name', 'On Day B');
    }

    #[Test]
    public function list_auto_closes_after_scheduled_day_in_parish_timezone(): void
    {
        $timezone = 'Pacific/Auckland';
        $tenant = Tenant::factory()->create([
            'features' => ['mass_intentions'],
            'currency_code' => 'USD',
            'settings' => ['timezone' => $timezone, 'language' => 'en', 'currency' => 'USD'],
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create']);

        Passport::actingAs($user);

        $yesterday = Carbon::now($timezone)->subDay()->toDateString();

        $id = $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'beneficiary_name' => 'Past Day',
            'requested_date' => $yesterday,
            'mass_intention_category_id' => $this->massIntentionCategoryIdFor($user, 'THANKSGIVING'),
            'intention_description' => 'Thanksgiving Mass.',
        ]))->assertCreated()->json('data.id');

        $this->getJson('/api/tenant/mass-intentions/requests')
            ->assertOk();

        $this->getJson("/api/tenant/mass-intentions/requests/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', MassIntentionStatus::CLOSED)
            ->assertJsonPath('data.close_source', MassIntentionCloseSource::AUTOMATIC);
    }

    #[Test]
    public function list_defaults_to_requested_date_asc_and_supports_sort(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create']);

        Passport::actingAs($user);

        $dayA = Carbon::now('UTC')->addDays(10)->toDateString();
        $dayB = Carbon::now('UTC')->addDays(20)->toDateString();

        $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'beneficiary_name' => 'Later',
            'requested_date' => $dayB,
            'mass_intention_category_id' => $this->massIntentionCategoryIdFor($user, 'THANKSGIVING'),
        ]))->assertCreated();

        $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'beneficiary_name' => 'Earlier',
            'requested_date' => $dayA,
            'mass_intention_category_id' => $this->massIntentionCategoryIdFor($user, 'THANKSGIVING'),
        ]))->assertCreated();

        $defaultOrder = $this->getJson('/api/tenant/mass-intentions/requests?status=open')
            ->assertOk()
            ->json('data');

        $this->assertSame('Earlier', $defaultOrder[0]['beneficiary_name']);

        $nameDesc = $this->getJson('/api/tenant/mass-intentions/requests?status=open&sort=beneficiary_name&direction=desc')
            ->assertOk()
            ->json('data');

        $this->assertSame('Later', $nameDesc[0]['beneficiary_name']);
    }

    #[Test]
    public function register_pdf_export_requires_export_permission_and_returns_pdf(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.create']);

        Passport::actingAs($user);

        $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($user, [
            'mass_intention_category_id' => $this->massIntentionCategoryIdFor($user, 'FAITHFUL_DEPARTED'),
        ]))->assertCreated();

        $this->get('/api/tenant/mass-intentions/requests/export/pdf')->assertForbidden();

        $this->grantPermissions($user, ['mass.intentions.register.export']);

        $response = $this->get('/api/tenant/mass-intentions/requests/export/pdf');
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
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
