<?php

namespace Modules\MassIntentions\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCC;
use Modules\Family\Database\Factories\FamilyFactory;
use Modules\Family\Database\Factories\FamilyMemberFactory;
use Modules\Family\Database\Factories\PersonFactory;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Tests\Support\CreatesMassIntentionTestPayload;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassIntentionBeneficiaryBccPlaceApiTest extends TestCase
{
    use CreatesMassIntentionTestPayload;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
        $this->tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'active' => 1]);
        $this->grantPermissions($this->user, ['mass.intentions.view', 'mass.intentions.create']);
        Passport::actingAs($this->user);
    }

    #[Test]
    public function external_intention_requires_place(): void
    {
        $payload = $this->validMassIntentionRequestPayload($this->user);
        unset($payload['beneficiary_place']);

        $this->postJson('/api/tenant/mass-intentions/requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['beneficiary_place']);
    }

    #[Test]
    public function external_intention_stores_place_and_clears_bcc(): void
    {
        $payload = $this->validMassIntentionRequestPayload($this->user, [
            'beneficiary_place' => 'Kottayam',
        ]);

        $this->postJson('/api/tenant/mass-intentions/requests', $payload)
            ->assertCreated()
            ->assertJsonPath('data.beneficiary_place', 'Kottayam')
            ->assertJsonPath('data.beneficiary_bcc', null);
    }

    #[Test]
    public function linked_member_snapshots_family_bcc_and_clears_place(): void
    {
        $bcc = BCC::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'St Joseph BCC',
        ]);
        $family = FamilyFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => $bcc->id,
        ]);
        $person = PersonFactory::new()->active()->create(['tenant_id' => $this->tenant->id]);
        FamilyMemberFactory::new()->create([
            'family_id' => $family->id,
            'person_id' => $person->id,
        ]);

        $payload = $this->validMassIntentionRequestPayload($this->user, [
            'beneficiary_person_id' => $person->id,
            'beneficiary_place' => null,
        ]);
        unset($payload['beneficiary_place']);

        $this->postJson('/api/tenant/mass-intentions/requests', $payload)
            ->assertCreated()
            ->assertJsonPath('data.beneficiary_person_id', $person->id)
            ->assertJsonPath('data.beneficiary_place', null)
            ->assertJsonPath('data.beneficiary_bcc.id', $bcc->id)
            ->assertJsonPath('data.beneficiary_bcc.name', 'St Joseph BCC');
    }

    #[Test]
    public function linked_member_without_bcc_is_allowed(): void
    {
        $family = FamilyFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => null,
        ]);
        $person = PersonFactory::new()->active()->create(['tenant_id' => $this->tenant->id]);
        FamilyMemberFactory::new()->create([
            'family_id' => $family->id,
            'person_id' => $person->id,
        ]);

        $payload = $this->validMassIntentionRequestPayload($this->user, [
            'beneficiary_person_id' => $person->id,
        ]);
        unset($payload['beneficiary_place']);

        $this->postJson('/api/tenant/mass-intentions/requests', $payload)
            ->assertCreated()
            ->assertJsonPath('data.beneficiary_bcc', null);
    }

    #[Test]
    public function rejects_client_supplied_bcc_id(): void
    {
        $payload = $this->validMassIntentionRequestPayload($this->user, [
            'beneficiary_bcc_id' => '00000000-0000-4000-8000-000000000001',
        ]);

        $this->postJson('/api/tenant/mass-intentions/requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['beneficiary_bcc_id']);
    }

    #[Test]
    public function list_search_matches_place_and_bcc_name(): void
    {
        $this->postJson('/api/tenant/mass-intentions/requests', $this->validMassIntentionRequestPayload($this->user, [
            'beneficiary_name' => 'Unique External',
            'beneficiary_place' => 'Alpine Village',
        ]))->assertCreated();

        $bcc = BCC::factory()->active()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Riverdale Community',
        ]);
        $family = FamilyFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => $bcc->id,
        ]);
        $person = PersonFactory::new()->active()->create(['tenant_id' => $this->tenant->id]);
        FamilyMemberFactory::new()->create([
            'family_id' => $family->id,
            'person_id' => $person->id,
        ]);

        $memberPayload = $this->validMassIntentionRequestPayload($this->user, [
            'beneficiary_name' => 'Member With BCC',
            'beneficiary_person_id' => $person->id,
        ]);
        unset($memberPayload['beneficiary_place']);
        $this->postJson('/api/tenant/mass-intentions/requests', $memberPayload)->assertCreated();

        $byPlace = $this->getJson('/api/tenant/mass-intentions/requests?status=open&search=Alpine')
            ->assertOk()
            ->json('data');
        $this->assertNotEmpty($byPlace);
        $this->assertSame('Unique External', $byPlace[0]['beneficiary_name']);

        $byBcc = $this->getJson('/api/tenant/mass-intentions/requests?status=open&search=Riverdale')
            ->assertOk()
            ->json('data');
        $this->assertNotEmpty($byBcc);
        $this->assertSame('Member With BCC', $byBcc[0]['beneficiary_name']);
    }

    #[Test]
    public function update_switching_to_external_clears_bcc(): void
    {
        $bcc = BCC::factory()->active()->create(['tenant_id' => $this->tenant->id]);
        $family = FamilyFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => $bcc->id,
        ]);
        $person = PersonFactory::new()->active()->create(['tenant_id' => $this->tenant->id]);
        FamilyMemberFactory::new()->create([
            'family_id' => $family->id,
            'person_id' => $person->id,
        ]);

        $categoryId = $this->massIntentionCategoryIdFor($this->user);
        $id = $this->postJson('/api/tenant/mass-intentions/requests', [
            'beneficiary_name' => 'Linked First',
            'beneficiary_person_id' => $person->id,
            'mass_intention_category_id' => $categoryId,
            'requested_date' => Carbon::now()->addWeek()->toDateString(),
            'mass_count' => 1,
        ])->json('data.id');

        $this->putJson("/api/tenant/mass-intentions/requests/{$id}", [
            'beneficiary_name' => 'Now External',
            'beneficiary_person_id' => null,
            'beneficiary_place' => 'Far Town',
            'mass_intention_category_id' => $categoryId,
            'requested_date' => Carbon::now()->addWeek()->toDateString(),
            'mass_count' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('data.beneficiary_place', 'Far Town')
            ->assertJsonPath('data.beneficiary_bcc', null);
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
