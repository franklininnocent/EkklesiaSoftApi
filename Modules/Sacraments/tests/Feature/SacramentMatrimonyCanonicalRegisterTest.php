<?php

namespace Modules\Sacraments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentCertificate;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Support\SacramentAnnotationType;
use Modules\Sacraments\Support\SacramentCertificateStatus;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SacramentMatrimonyCanonicalRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $user;

    protected SacramentType $baptismType;

    protected SacramentType $marriageType;

    protected Family $family;

    protected FamilyMember $bride;

    protected FamilyMember $groom;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tenant = Tenant::factory()->create();
        $role = Role::create([
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
            'role_id' => $role->id,
        ]);
        $this->user->syncRoles([$role->id]);
        $this->grantPermissions($role);

        $this->baptismType = SacramentType::factory()->create([
            'name' => 'Baptism',
            'code' => 'BAPTISM',
            'active' => true,
            'requires_minister' => true,
        ]);
        $this->marriageType = SacramentType::factory()->create([
            'name' => 'Marriage',
            'code' => 'MATRIMONY',
            'active' => true,
            'requires_minister' => true,
        ]);

        $this->family = Family::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->bride = FamilyMember::factory()->create([
            'family_id' => $this->family->id,
            'first_name' => 'Maria',
            'middle_name' => null,
            'last_name' => 'Teresa',
        ]);
        $this->groom = FamilyMember::factory()->create([
            'family_id' => $this->family->id,
            'first_name' => 'Joseph',
            'middle_name' => null,
            'last_name' => 'Francis',
        ]);

        Passport::actingAs($this->user);
    }

    private function grantPermissions(Role $role): void
    {
        $names = [
            'sacraments.view', 'sacraments.create', 'sacraments.edit',
            'sacraments.correct', 'sacraments.void', 'sacraments.delete', 'sacraments.restore',
            'certificate.generate', 'certificate.download', 'certificate.reissue',
        ];
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function marriagePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'sacrament_type_id' => $this->marriageType->id,
            'date_administered' => '2026-08-10',
            'place_administered' => 'Sacred Heart Church',
            'registry_entry' => 'LM-12',
            'participants' => [
                [
                    'role' => 'bride',
                    'source' => 'external',
                    'sort_order' => 0,
                    'external_full_name' => 'Maria Teresa',
                    'external_date_of_birth' => '1992-03-10',
                    'external_gender' => 'female',
                    'baptismal_status' => 'baptized_catholic',
                    'ecclesial_affiliation_code' => 'roman_catholic',
                    'father_name' => 'Thomas Joseph',
                    'mother_name' => 'Anna Joseph',
                    'affiliation_type' => 'home_parish',
                ],
                [
                    'role' => 'groom',
                    'source' => 'external',
                    'sort_order' => 1,
                    'external_full_name' => 'Joseph Francis',
                    'external_date_of_birth' => '1990-06-20',
                    'external_gender' => 'male',
                    'baptismal_status' => 'baptized_catholic',
                    'ecclesial_affiliation_code' => 'roman_catholic',
                    'father_name' => 'Francis Xavier',
                    'mother_name' => 'Mary Xavier',
                    'affiliation_type' => 'home_parish',
                ],
                [
                    'role' => 'witness',
                    'source' => 'external',
                    'sort_order' => 0,
                    'external_full_name' => 'Peter D’Souza',
                    'external_gender' => 'male',
                    'external_address' => 'Kadayal',
                    'external_contact_number' => '9876543210',
                ],
                [
                    'role' => 'witness',
                    'source' => 'external',
                    'sort_order' => 1,
                    'external_full_name' => 'Agnes Fernandez',
                    'external_gender' => 'female',
                    'external_address' => 'Kadayal',
                    'external_contact_number' => '9876543211',
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'sort_order' => 2,
                    'external_full_name' => 'Fr. Thomas',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                    'canonical_delegation_status' => 'proper_pastor',
                ],
            ],
        ], $overrides);
    }

    private function createMarriage(array $overrides = []): Sacrament
    {
        $response = $this->postJson('/api/sacraments', $this->marriagePayload($overrides));
        $response->assertCreated();

        return Sacrament::findOrFail($response->json('data.id'));
    }

    #[Test]
    public function it_adds_canonical_register_schema(): void
    {
        $this->assertTrue(Schema::hasColumn('sacrament_participants', 'baptismal_status'));
        $this->assertTrue(Schema::hasColumn('sacrament_participants', 'ecclesial_affiliation_code'));
        $this->assertTrue(Schema::hasColumn('sacrament_participants', 'canonical_delegation_status'));
        $this->assertTrue(Schema::hasColumn('sacraments', 'marriage_canonical_classification'));
        $this->assertTrue(Schema::hasTable('sacrament_dispensations'));
        $this->assertTrue(Schema::hasTable('sacrament_canonical_annotations'));
    }

    #[Test]
    public function it_derives_both_catholic_without_dispensation(): void
    {
        $sacrament = $this->createMarriage();

        $this->assertSame('both_catholic', $sacrament->marriage_canonical_classification);
        $this->assertCount(0, $sacrament->dispensations);
    }

    #[Test]
    public function mixed_marriage_requires_a_dispensation(): void
    {
        $payload = $this->marriagePayload();
        $payload['participants'][1]['baptismal_status'] = 'baptized_non_catholic';
        $payload['participants'][1]['ecclesial_affiliation_code'] = 'csi';

        $this->postJson('/api/sacraments', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'dispensation_required');

        $payload['dispensations'] = [[
            'dispensation_type' => 'mixed_marriage_permission',
            'granting_authority' => 'Bishop of Kuzhithurai',
            'protocol_number' => 'D-44',
            'date_granted' => '2026-07-01',
        ]];

        $sacrament = $this->createMarriage($payload);
        $this->assertSame('mixed_marriage', $sacrament->marriage_canonical_classification);
        $this->assertSame('mixed_marriage_permission', $sacrament->dispensations()->first()?->dispensation_type);
    }

    #[Test]
    public function annotations_are_register_only_and_never_on_the_certificate(): void
    {
        $sacrament = $this->createMarriage();

        $this->postJson("/api/sacraments/{$sacrament->id}/canonical-annotations", [
            'annotation_type' => SacramentAnnotationType::BAPTISMAL_REGISTER_NOTATION,
            'effective_date' => '2026-08-12',
            'granting_authority' => 'Sacred Heart Church, Kadayal',
            'protocol_number' => 'BR-9',
            'notes' => 'Noted in the baptismal register of the Catholic party.',
        ])->assertCreated();

        $list = $this->getJson("/api/sacraments/{$sacrament->id}/canonical-annotations");
        $list->assertOk();
        $this->assertSame(
            SacramentAnnotationType::BAPTISMAL_REGISTER_NOTATION,
            $list->json('data.0.annotation_type')
        );

        $show = $this->getJson("/api/sacraments/{$sacrament->id}");
        $show->assertOk()
            ->assertJsonPath('data.canonical_annotations.0.annotation_type_label', 'Noted in baptismal register')
            ->assertJsonPath('data.marriage_canonical_classification', 'both_catholic');

        $preview = $this->postJson("/api/sacraments/{$sacrament->id}/certificates/preview");
        $preview->assertCreated();
        $view = $preview->json('data.projection.certificate_view');
        $this->assertSame(['Peter D’Souza', 'Agnes Fernandez'], $view['witnesses']);
        $this->assertSame('LM-12', $view['registry']['registryEntry']);
        $this->assertArrayNotHasKey('canonical_annotations', $view);
        $this->assertArrayNotHasKey('dispensations', $view);
        $this->assertArrayNotHasKey('marriage_canonical_classification', $view);
        $this->assertArrayNotHasKey('classification', $view);
        $this->assertStringNotContainsString('Noted in the baptismal register', json_encode($view));
        $this->assertSame('1.1.0', $preview->json('data.template_version'));

        $gen = $this->postJson("/api/sacraments/{$sacrament->id}/certificates/generate");
        $gen->assertCreated();
        $cert = SacramentCertificate::findOrFail($gen->json('data.id'));
        $this->assertSame(SacramentCertificateStatus::ISSUED, $cert->status);
        $issuedView = $cert->projection_json['certificate_view'] ?? [];
        $this->assertNotEmpty($issuedView['issuedAt'] ?? null);
        $this->assertArrayNotHasKey('canonical_annotations', $issuedView);

        $print = $this->get("/api/sacraments/certificates/{$cert->id}/print");
        $print->assertOk();
        $html = $print->getContent();
        $this->assertStringContainsString('Peter D’Souza', $html);
        $this->assertStringContainsString('Entry No.', $html);
        $this->assertStringNotContainsString('Noted in the baptismal register', $html);
        $this->assertStringNotContainsString('mixed_marriage', $html);
        $this->assertStringNotContainsString('Dispensation', $html);
    }

    #[Test]
    public function member_rename_does_not_change_issued_marriage_snapshot(): void
    {
        $payload = $this->marriagePayload();
        $payload['participants'][0] = [
            'role' => 'bride',
            'source' => 'member',
            'sort_order' => 0,
            'family_member_id' => $this->bride->id,
            'baptismal_status' => 'baptized_catholic',
            'affiliation_type' => 'home_parish',
        ];
        $payload['participants'][1] = [
            'role' => 'groom',
            'source' => 'member',
            'sort_order' => 1,
            'family_member_id' => $this->groom->id,
            'baptismal_status' => 'baptized_catholic',
            'affiliation_type' => 'home_parish',
        ];

        $sacrament = $this->createMarriage($payload);
        $gen = $this->postJson("/api/sacraments/{$sacrament->id}/certificates/generate");
        $gen->assertCreated();
        $cert = SacramentCertificate::findOrFail($gen->json('data.id'));
        $this->assertSame('Maria Teresa', $cert->projection_json['certificate_view']['bride']['fullName'] ?? null);
        $this->assertSame('Joseph Francis', $cert->projection_json['certificate_view']['groom']['fullName'] ?? null);

        $this->bride->person->update(['first_name' => 'Renamed', 'last_name' => 'Bride']);
        $this->groom->person->update(['first_name' => 'Renamed', 'last_name' => 'Groom']);
        $this->bride->update(['first_name' => 'Renamed', 'last_name' => 'Bride']);
        $this->groom->update(['first_name' => 'Renamed', 'last_name' => 'Groom']);

        $cert->refresh();
        $this->assertSame('Maria Teresa', $cert->projection_json['certificate_view']['bride']['fullName'] ?? null);
        $this->assertSame('Joseph Francis', $cert->projection_json['certificate_view']['groom']['fullName'] ?? null);
    }

    #[Test]
    public function baptism_cannot_receive_canonical_annotations(): void
    {
        $baptism = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2026-08-01',
            'place_administered' => 'St. Mary',
            'recipient_birth_date' => '2015-04-10',
            'recipient_birth_place' => 'Parish City',
            'recipient_gender' => 'female',
            'father_name' => 'Thomas Joseph',
            'mother_name' => 'Anna Joseph',
            'participants' => [
                [
                    'role' => 'recipient',
                    'source' => 'member',
                    'family_member_id' => $this->bride->id,
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. Thomas',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                ],
            ],
        ]);
        $baptism->assertCreated();
        $id = $baptism->json('data.id');

        $this->postJson("/api/sacraments/{$id}/canonical-annotations", [
            'annotation_type' => SacramentAnnotationType::CONVALIDATION,
        ])->assertStatus(422)->assertJsonPath('code', 'annotations_not_supported');
    }
}
