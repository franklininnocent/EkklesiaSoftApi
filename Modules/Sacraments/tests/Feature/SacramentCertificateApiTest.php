<?php

namespace Modules\Sacraments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
use Modules\Sacraments\Support\SacramentCertificateStatus;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 8 — Certificate preview / generate / download / supersede on correct.
 */
class SacramentCertificateApiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $user;

    protected SacramentType $baptismType;

    protected SacramentType $marriageType;

    protected FamilyMember $member;

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

        $family = Family::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->member = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'Anna',
            'middle_name' => null,
            'last_name' => 'Recipient',
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

    private function createBaptism(): Sacrament
    {
        $response = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2026-08-01',
            'place_administered' => 'St. Mary',
            'recipient_birth_date' => '2015-04-10',
            'recipient_birth_place' => 'Parish City',
            'recipient_gender' => 'female',
            'father_name' => 'John Father',
            'mother_name' => 'Jane Mother',
            'participants' => [
                [
                    'role' => 'recipient',
                    'source' => 'member',
                    'family_member_id' => $this->member->id,
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
        $response->assertCreated();

        return Sacrament::findOrFail($response->json('data.id'));
    }

    private function createMarriage(): Sacrament
    {
        $response = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->marriageType->id,
            'date_administered' => '2026-08-10',
            'place_administered' => 'Cathedral',
            'participants' => [
                [
                    'role' => 'bride',
                    'source' => 'external',
                    'external_full_name' => 'Eve Bride',
                    'external_date_of_birth' => '1992-03-10',
                    'external_gender' => 'female',
                    'affiliation_type' => 'other',
                    'affiliation_parish_name' => 'St. Joseph',
                    'affiliation_diocese_name' => 'Diocese X',
                ],
                [
                    'role' => 'groom',
                    'source' => 'external',
                    'external_full_name' => 'Adam Groom',
                    'external_date_of_birth' => '1990-06-20',
                    'external_gender' => 'male',
                    'affiliation_type' => 'home_parish',
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. Celebrant',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                ],
            ],
        ]);
        $response->assertCreated();

        return Sacrament::findOrFail($response->json('data.id'));
    }

    #[Test]
    public function it_previews_baptism_certificate_without_file(): void
    {
        $sacrament = $this->createBaptism();

        $response = $this->postJson("/api/sacraments/{$sacrament->id}/certificates/preview");

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', SacramentCertificateStatus::DRAFT_PREVIEW)
            ->assertJsonPath('data.has_file', false)
            ->assertJsonPath('data.template_code', 'baptism_v1');

        $this->assertNotEmpty($response->json('data.projection.participants'));
        $this->assertNull(SacramentCertificate::find($response->json('data.id'))->storage_key);
    }

    #[Test]
    public function it_generates_baptism_and_marriage_certificates(): void
    {
        $baptism = $this->createBaptism();
        $marriage = $this->createMarriage();

        $b = $this->postJson("/api/sacraments/{$baptism->id}/certificates/generate");
        $b->assertCreated()
            ->assertJsonPath('data.status', SacramentCertificateStatus::ISSUED)
            ->assertJsonPath('data.has_file', true)
            ->assertJsonPath('data.template_code', 'baptism_v1');

        $m = $this->postJson("/api/sacraments/{$marriage->id}/certificates/generate");
        $m->assertCreated()
            ->assertJsonPath('data.status', SacramentCertificateStatus::ISSUED)
            ->assertJsonPath('data.template_code', 'marriage_v1');

        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get(
            SacramentCertificate::find($b->json('data.id'))->storage_key
        ));
    }

    #[Test]
    public function member_rename_does_not_change_issued_projection(): void
    {
        $sacrament = $this->createBaptism();
        $gen = $this->postJson("/api/sacraments/{$sacrament->id}/certificates/generate");
        $gen->assertCreated();

        $cert = SacramentCertificate::findOrFail($gen->json('data.id'));
        $frozenName = collect($cert->projection_json['participants'] ?? [])
            ->firstWhere('role', 'recipient')['display_name'] ?? null;
        $this->assertSame('Anna Recipient', $frozenName);
        $checksum = $cert->checksum;

        $this->member->update(['first_name' => 'Renamed', 'last_name' => 'Person']);
        $this->member->refresh();

        $cert->refresh();
        $still = collect($cert->projection_json['participants'] ?? [])
            ->firstWhere('role', 'recipient')['display_name'] ?? null;
        $this->assertSame('Anna Recipient', $still);
        $this->assertSame($checksum, $cert->checksum);
    }

    #[Test]
    public function download_is_audited_and_streams_pdf(): void
    {
        $sacrament = $this->createBaptism();
        $gen = $this->postJson("/api/sacraments/{$sacrament->id}/certificates/generate");
        $certId = $gen->json('data.id');

        $download = $this->get("/api/sacraments/certificates/{$certId}/download");
        $download->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $download->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $download->getContent());

        $this->assertDatabaseHas('sacrament_audit_logs', [
            'event' => 'certificate_download',
            'target_type' => 'sacrament_certificate',
            'target_id' => (string) $certId,
        ]);
    }

    #[Test]
    public function correct_supersedes_issued_certificate(): void
    {
        $sacrament = $this->createBaptism();
        $gen = $this->postJson("/api/sacraments/{$sacrament->id}/certificates/generate");
        $certId = $gen->json('data.id');

        $this->postJson("/api/sacraments/{$sacrament->id}/correct", [
            'lock_version' => $sacrament->fresh()->lock_version,
            'reason' => 'Fix place spelling',
            'place_administered' => 'Saint Mary',
        ])->assertOk();

        $this->assertSame(
            SacramentCertificateStatus::SUPERSEDED,
            SacramentCertificate::findOrFail($certId)->status
        );
    }

    #[Test]
    public function preview_cannot_be_downloaded(): void
    {
        $sacrament = $this->createBaptism();
        $preview = $this->postJson("/api/sacraments/{$sacrament->id}/certificates/preview");
        $certId = $preview->json('data.id');

        $this->getJson("/api/sacraments/certificates/{$certId}/download")
            ->assertStatus(422)
            ->assertJsonPath('code', 'certificate_not_issued');
    }
}
