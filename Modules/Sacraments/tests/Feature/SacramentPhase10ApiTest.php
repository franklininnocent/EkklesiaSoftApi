<?php

namespace Modules\Sacraments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Sacraments\Models\SacramentType;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 10B/10C — Confirmation, Eucharist, Anointing, Reconciliation privacy, Holy Orders.
 */
class SacramentPhase10ApiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $user;

    protected User $restrictedUser;

    protected Role $role;

    protected Role $restrictedRole;

    protected Family $family;

    protected FamilyMember $member;

    protected SacramentType $confirmationType;

    protected SacramentType $eucharistType;

    protected SacramentType $anointingType;

    protected SacramentType $reconciliationType;

    protected SacramentType $holyOrdersType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->role = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->role->id,
        ]);
        $this->user->syncRoles([$this->role->id]);
        $this->grantPermissions($this->role, [
            'sacraments.view', 'sacraments.create', 'sacraments.edit', 'sacraments.delete',
            'certificate.generate', 'certificate.download',
        ]);

        $this->restrictedRole = Role::create([
            'name' => 'Tenant Restricted Sacraments',
            'description' => 'Restricted',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);
        $this->restrictedUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->restrictedRole->id,
        ]);
        $this->restrictedUser->syncRoles([$this->restrictedRole->id]);
        $this->grantPermissions($this->restrictedRole, [
            'sacraments.view', 'sacraments.create', 'sacraments.edit',
            'sacraments.view_restricted',
        ]);

        $this->confirmationType = SacramentType::factory()->create([
            'name' => 'Confirmation', 'code' => 'CONFIRMATION', 'active' => true, 'requires_minister' => true, 'repeatable' => false,
        ]);
        $this->eucharistType = SacramentType::factory()->create([
            'name' => 'Eucharist', 'code' => 'EUCHARIST', 'active' => true, 'requires_minister' => true, 'repeatable' => true,
        ]);
        $this->anointingType = SacramentType::factory()->create([
            'name' => 'Anointing', 'code' => 'ANOINTING', 'active' => true, 'requires_minister' => true, 'repeatable' => true,
        ]);
        $this->reconciliationType = SacramentType::factory()->create([
            'name' => 'Reconciliation', 'code' => 'RECONCILIATION', 'active' => true, 'requires_minister' => true, 'repeatable' => true,
        ]);
        $this->holyOrdersType = SacramentType::factory()->create([
            'name' => 'Holy Orders', 'code' => 'HOLY_ORDERS', 'active' => true, 'requires_minister' => true, 'repeatable' => false,
        ]);

        $this->family = Family::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->member = FamilyMember::factory()->create([
            'family_id' => $this->family->id,
            'first_name' => 'Pat',
            'middle_name' => null,
            'last_name' => 'Confirmand',
        ]);

        Passport::actingAs($this->user);
    }

    /**
     * @param  list<string>  $names
     */
    private function grantPermissions(Role $role, array $names): void
    {
        $ids = [];
        foreach ($names as $name) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => 'Test',
                    'module' => 'Sacraments',
                    'scope' => Permission::SCOPE_TENANT,
                    'category' => 'sacraments',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            );
            $ids[] = $permission->id;
        }
        $role->permissions()->syncWithoutDetaching($ids);
    }

    private function externalMinister(): array
    {
        return [
            'role' => 'minister',
            'source' => 'external',
            'external_full_name' => 'Fr. External',
            'external_title' => 'Father',
            'external_minister_role' => 'priest',
        ];
    }

    private function memberRecipient(): array
    {
        return [
            'role' => 'recipient',
            'source' => 'member',
            'family_member_id' => $this->member->id,
        ];
    }

    /** @return array<string, string> */
    private function eucharistRequiredFields(): array
    {
        return [
            'place_administered' => 'Parish Church',
            'recipient_birth_date' => '2010-03-15',
            'recipient_birth_place' => 'Parish City',
            'recipient_gender' => 'male',
            'father_name' => 'Joseph Communicant',
            'mother_name' => 'Mary Communicant',
            'baptism_date' => '2012-05-01',
        ];
    }

    #[Test]
    public function it_exposes_phase10_definition_metadata(): void
    {
        $response = $this->getJson('/api/sacraments/definitions');
        $response->assertOk();

        $byCode = collect($response->json('data'))->keyBy('code');
        $this->assertSame('sensitive', $byCode['ANOINTING']['privacy_class']);
        $this->assertSame('restricted', $byCode['RECONCILIATION']['privacy_class']);
        $this->assertFalse($byCode['RECONCILIATION']['certificate_supported']);
        $this->assertTrue($byCode['CONFIRMATION']['batch_supported']);
        $this->assertContains('FIRST_COMMUNION', $byCode['EUCHARIST']['event_subtypes']);
        $this->assertContains('DIACONATE', $byCode['HOLY_ORDERS']['ordination_types']);
        $this->assertFalse($byCode['RECONCILIATION']['gated']);
    }

    #[Test]
    public function it_creates_confirmation_with_sponsors(): void
    {
        $response = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->confirmationType->id,
            'date_administered' => '2026-08-10',
            'participants' => [
                $this->memberRecipient(),
                [
                    'role' => 'sponsor',
                    'source' => 'external',
                    'external_full_name' => 'Sponsor One',
                ],
                array_merge($this->externalMinister(), ['external_minister_role' => 'bishop']),
            ],
        ]);

        $response->assertCreated()->assertJsonPath('success', true);
        $this->assertSame($this->confirmationType->id, (int) $response->json('data.sacrament_type_id'));
        $this->assertStringContainsString('Confirmand', (string) $response->json('data.recipient_name'));
    }

    #[Test]
    public function it_creates_eucharist_with_first_communion_subtype(): void
    {
        $response = $this->postJson('/api/sacraments', array_merge($this->eucharistRequiredFields(), [
            'sacrament_type_id' => $this->eucharistType->id,
            'date_administered' => '2026-08-10',
            'participants' => [
                $this->memberRecipient(),
                $this->externalMinister(),
            ],
        ]));

        $response->assertCreated();
        $this->assertSame('FIRST_COMMUNION', $response->json('data.event_subtype'));
        $this->assertStringContainsString('2012-05-01', (string) $response->json('data.baptism_date'));
    }

    #[Test]
    public function it_requires_baptism_date_and_birth_fields_for_eucharist(): void
    {
        $response = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->eucharistType->id,
            'date_administered' => '2026-08-10',
            'participants' => [
                $this->memberRecipient(),
                $this->externalMinister(),
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'place_administered',
                'recipient_birth_date',
                'recipient_birth_place',
                'recipient_gender',
                'father_name',
                'mother_name',
                'baptism_date',
            ]);
    }

    #[Test]
    public function it_warns_on_duplicate_first_communion_for_same_person(): void
    {
        $payload = array_merge($this->eucharistRequiredFields(), [
            'sacrament_type_id' => $this->eucharistType->id,
            'date_administered' => '2026-08-10',
            'participants' => [
                $this->memberRecipient(),
                $this->externalMinister(),
            ],
        ]);
        $this->postJson('/api/sacraments', $payload)->assertCreated();

        $dup = $this->postJson('/api/sacraments', array_merge($payload, [
            'date_administered' => '2026-09-01',
        ]));
        $dup->assertStatus(422)->assertJsonPath('code', 'duplicate_sacrament');

        $ack = $this->postJson('/api/sacraments', array_merge($payload, [
            'date_administered' => '2026-09-01',
            'acknowledge_duplicate_warning' => true,
        ]));
        $ack->assertCreated();
    }

    #[Test]
    public function it_creates_anointing_with_place_classification_and_rejects_medical_fields(): void
    {
        $ok = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->anointingType->id,
            'date_administered' => '2026-08-10',
            'place_classification' => 'hospital',
            'participants' => [
                $this->memberRecipient(),
                $this->externalMinister(),
            ],
        ]);
        $ok->assertCreated();
        $this->assertSame('hospital', $ok->json('data.place_classification'));

        $bad = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->anointingType->id,
            'date_administered' => '2026-08-11',
            'diagnosis' => 'flu',
            'participants' => [
                $this->memberRecipient(),
                $this->externalMinister(),
            ],
        ]);
        $bad->assertStatus(422);
    }

    #[Test]
    public function it_blocks_reconciliation_without_restricted_permission(): void
    {
        $response = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->reconciliationType->id,
            'date_administered' => '2026-08-10',
            'participants' => [
                $this->memberRecipient(),
                $this->externalMinister(),
            ],
        ]);

        $response->assertStatus(403)->assertJsonPath('code', 'restricted_access_required');
    }

    #[Test]
    public function it_creates_reconciliation_with_restricted_permission_and_hides_from_list(): void
    {
        Passport::actingAs($this->restrictedUser);

        $create = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->reconciliationType->id,
            'date_administered' => '2026-08-10',
            'notes' => 'Admin visit only',
            'participants' => [
                $this->memberRecipient(),
                $this->externalMinister(),
            ],
        ]);
        $create->assertCreated();
        $id = $create->json('data.id');

        Passport::actingAs($this->user);
        $list = $this->getJson('/api/sacraments');
        $list->assertOk();
        $ids = collect($list->json('data.data') ?? $list->json('data'))->pluck('id')->all();
        $this->assertNotContains($id, $ids);

        $show = $this->getJson('/api/sacraments/'.$id);
        $show->assertStatus(403)->assertJsonPath('code', 'restricted_access_required');

        Passport::actingAs($this->restrictedUser);
        $this->getJson('/api/sacraments/'.$id)->assertOk();
    }

    #[Test]
    public function it_rejects_confession_content_fields(): void
    {
        Passport::actingAs($this->restrictedUser);

        $response = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->reconciliationType->id,
            'date_administered' => '2026-08-10',
            'confession_text' => 'secret',
            'participants' => [
                $this->memberRecipient(),
                $this->externalMinister(),
            ],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_creates_holy_orders_with_typed_attributes_and_co_consecrator(): void
    {
        $response = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->holyOrdersType->id,
            'date_administered' => '2026-08-10',
            'typed_attributes' => [
                'ordination_type' => 'PRESBYTERATE',
                'diocese_name' => 'Sample Diocese',
            ],
            'participants' => [
                [
                    'role' => 'candidate',
                    'source' => 'member',
                    'family_member_id' => $this->member->id,
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Bishop Ordainer',
                    'external_title' => 'Bishop',
                    'external_minister_role' => 'bishop',
                ],
                [
                    'role' => 'co_consecrator',
                    'source' => 'external',
                    'external_full_name' => 'Bishop Two',
                    'external_minister_role' => 'bishop',
                    'sort_order' => 1,
                ],
            ],
        ]);

        $response->assertCreated();
        $this->assertSame('PRESBYTERATE', $response->json('data.typed_attributes.ordination_type'));
        $this->assertNotEmpty($response->json('data.recipient_name'));
        $this->assertStringContainsString('Confirmand', (string) $response->json('data.recipient_name'));
    }

    #[Test]
    public function it_requires_ordination_type_for_holy_orders(): void
    {
        $response = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->holyOrdersType->id,
            'date_administered' => '2026-08-10',
            'participants' => [
                [
                    'role' => 'candidate',
                    'source' => 'external',
                    'external_full_name' => 'Candidate External',
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Bishop Ordainer',
                    'external_minister_role' => 'bishop',
                ],
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'ordination_type_required');
    }

    #[Test]
    public function it_generates_confirmation_certificate_preview(): void
    {
        $created = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->confirmationType->id,
            'date_administered' => '2026-08-10',
            'participants' => [
                $this->memberRecipient(),
                array_merge($this->externalMinister(), ['external_minister_role' => 'bishop']),
            ],
        ])->assertCreated();

        $id = $created->json('data.id');
        $preview = $this->postJson("/api/sacraments/{$id}/certificates/preview", [
            'language' => 'en',
            'locale' => 'en_US',
        ]);
        $preview->assertCreated();
    }
}
