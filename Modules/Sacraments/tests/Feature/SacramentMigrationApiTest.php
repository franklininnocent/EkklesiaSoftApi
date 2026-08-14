<?php

namespace Modules\Sacraments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentParticipant;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Services\Migration\SacramentParticipantBackfillService;
use Modules\Sacraments\Support\SacramentMigrationResolutionStatus;
use Modules\Sacraments\Support\SacramentParticipantSource;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 9 — migration backfill + reconciliation API.
 */
class SacramentMigrationApiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $user;

    protected SacramentType $baptismType;

    protected Family $family;

    protected FamilyMember $member;

    protected function setUp(): void
    {
        parent::setUp();

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
        ]);

        $this->family = Family::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->member = FamilyMember::factory()->create([
            'family_id' => $this->family->id,
            'first_name' => 'Anna',
            'middle_name' => null,
            'last_name' => 'Recipient',
            'date_of_birth' => '2010-05-01',
        ]);

        Passport::actingAs($this->user);
    }

    private function grantPermissions(Role $role): void
    {
        $names = [
            'sacraments.view', 'sacraments.create', 'sacraments.edit',
            'sacraments.migration.view', 'sacraments.migration.resolve',
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

    private function legacyBaptism(array $overrides = []): Sacrament
    {
        return Sacrament::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'recipient_name' => 'Anna Recipient',
            'recipient_birth_date' => '2010-05-01',
            'father_name' => 'John Father',
            'mother_name' => 'Mary Mother',
            'minister_name' => 'Fr. Thomas',
            'date_administered' => '2020-01-15',
            'status' => 'registered',
        ], $overrides));
    }

    #[Test]
    public function backfill_is_idempotent_and_links_exact_name_dob(): void
    {
        $sacrament = $this->legacyBaptism();

        $service = app(SacramentParticipantBackfillService::class);
        $first = $service->run($this->tenant->id, false, 50);
        $second = $service->run($this->tenant->id, false, 50);

        $this->assertGreaterThan(0, $first['linked']);
        $this->assertSame(0, $second['created_participants']);

        $this->assertDatabaseHas('sacrament_migration_resolutions', [
            'legacy_sacrament_id' => $sacrament->id,
            'participant_role' => 'recipient',
            'resolution' => SacramentMigrationResolutionStatus::MEMBER,
            'confidence' => SacramentMigrationResolutionStatus::CONFIDENCE_EXACT,
            'candidate_member_id' => $this->member->id,
        ]);

        $recipient = SacramentParticipant::where('sacrament_id', $sacrament->id)
            ->where('role', 'recipient')
            ->first();
        $this->assertNotNull($recipient);
        $this->assertSame(SacramentParticipantSource::MEMBER, $recipient->source);
        $this->assertSame($this->member->id, $recipient->family_member_id);

        $father = SacramentParticipant::where('sacrament_id', $sacrament->id)
            ->where('role', 'father')
            ->first();
        $this->assertSame(SacramentParticipantSource::UNRESOLVED, $father->source);
    }

    #[Test]
    public function name_only_does_not_auto_link(): void
    {
        $this->legacyBaptism([
            'recipient_name' => 'Anna Recipient',
            'recipient_birth_date' => null,
        ]);

        app(SacramentParticipantBackfillService::class)->run($this->tenant->id, false, 50);

        $this->assertDatabaseHas('sacrament_migration_resolutions', [
            'participant_role' => 'recipient',
            'resolution' => SacramentMigrationResolutionStatus::UNRESOLVED,
            'confidence' => SacramentMigrationResolutionStatus::CONFIDENCE_NONE,
        ]);
    }

    #[Test]
    public function report_and_resolve_via_api(): void
    {
        $this->legacyBaptism();
        $this->postJson('/api/sacraments/migration/backfill', ['dry_run' => false])
            ->assertOk()
            ->assertJsonPath('success', true);

        $report = $this->getJson('/api/sacraments/migration-resolutions/report');
        $report->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'total_resolutions',
                    'linked',
                    'unresolved',
                    'affiliation_incomplete',
                    'participants_v1',
                ],
            ]);
        $this->assertTrue($report->json('data.participants_v1'));

        $list = $this->getJson('/api/sacraments/migration-resolutions?resolution=unresolved');
        $list->assertOk();
        $rows = $list->json('data.data');
        $this->assertNotEmpty($rows);

        $fatherRow = collect($rows)->firstWhere('participant_role', 'father');
        $this->assertNotNull($fatherRow);

        $this->postJson("/api/sacraments/migration-resolutions/{$fatherRow['id']}/resolve", [
            'resolution' => 'external',
            'external_full_name' => 'John Father',
        ])->assertOk()
            ->assertJsonPath('data.resolution', 'external');

        $father = SacramentParticipant::where('role', 'father')->first();
        $this->assertSame(SacramentParticipantSource::EXTERNAL, $father->source);
        $this->assertSame('John Father', $father->external_full_name);
    }

    #[Test]
    public function definitions_meta_participants_v1_defaults_on(): void
    {
        $this->getJson('/api/sacraments/definitions')
            ->assertOk()
            ->assertJsonPath('meta.participants_v1', true);
    }
}
