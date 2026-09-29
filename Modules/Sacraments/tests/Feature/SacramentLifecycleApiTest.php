<?php

namespace Modules\Sacraments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentAuditLog;
use Modules\Sacraments\Models\SacramentParticipant;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Support\SacramentStatus;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 4 — Correct / Void / Soft-delete / Restore ≠ Unvoid / lock_version 409.
 */
class SacramentLifecycleApiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $user;

    protected SacramentType $baptismType;

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
            'requires_minister' => false,
        ]);

        Passport::actingAs($this->user);
    }

    private function grantPermissions(Role $role): void
    {
        $names = [
            'sacraments.view', 'sacraments.create', 'sacraments.edit',
            'sacraments.correct', 'sacraments.void', 'sacraments.delete', 'sacraments.restore',
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

    private function createRegisteredSacrament(array $overrides = []): Sacrament
    {
        return Sacrament::factory()->registered()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'recipient_name' => 'Lifecycle Child',
            'lock_version' => 0,
            'created_by' => $this->user->id,
        ], $overrides));
    }

    #[Test]
    public function it_corrects_sacrament_with_reason_audit_and_lock_bump(): void
    {
        $sacrament = $this->createRegisteredSacrament([
            'place_administered' => 'Old Place',
        ]);

        $response = $this->postJson("/api/sacraments/{$sacrament->id}/correct", [
            'lock_version' => 0,
            'reason' => 'Corrected place of baptism',
            'place_administered' => 'St. Mary Parish',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.place_administered', 'St. Mary Parish')
            ->assertJsonPath('data.lock_version', 1);

        $this->assertDatabaseHas('sacrament_audit_logs', [
            'tenant_id' => $this->tenant->id,
            'event' => 'correction',
            'target_id' => (string) $sacrament->id,
        ]);

        $audit = SacramentAuditLog::where('target_id', (string) $sacrament->id)
            ->where('event', 'correction')
            ->first();
        $this->assertSame('Corrected place of baptism', $audit->metadata['reason']);
        $this->assertNotEmpty($audit->old_values);
        $this->assertNotEmpty($audit->new_values);
    }

    #[Test]
    public function it_corrects_participants_and_rebuilds_snapshots(): void
    {
        $sacrament = $this->createRegisteredSacrament();
        SacramentParticipant::create([
            'tenant_id' => $this->tenant->id,
            'sacrament_id' => $sacrament->id,
            'role' => 'recipient',
            'source' => 'external',
            'external_full_name' => 'Old Name',
            'snapshot_json' => ['full_name' => 'Old Name', 'schema_version' => 1],
        ]);

        $response = $this->postJson("/api/sacraments/{$sacrament->id}/correct", [
            'lock_version' => 0,
            'reason' => 'Recipient name correction',
            'participants' => [
                [
                    'role' => 'recipient',
                    'source' => 'external',
                    'external_full_name' => 'New Name',
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. Correct',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.recipient_name', 'New Name')
            ->assertJsonPath('data.lock_version', 1);

        $this->assertSame(1, SacramentParticipant::where('sacrament_id', $sacrament->id)
            ->where('role', 'recipient')
            ->whereNull('deleted_at')
            ->count());

        $recipient = SacramentParticipant::where('sacrament_id', $sacrament->id)
            ->where('role', 'recipient')
            ->whereNull('deleted_at')
            ->first();
        $this->assertSame('New Name', $recipient->snapshot_json['full_name']);
    }

    #[Test]
    public function it_voids_sacrament_without_soft_deleting(): void
    {
        $sacrament = $this->createRegisteredSacrament();

        $response = $this->postJson("/api/sacraments/{$sacrament->id}/void", [
            'lock_version' => 0,
            'reason' => 'Entered in error',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', SacramentStatus::VOIDED)
            ->assertJsonPath('data.lock_version', 1);

        $this->assertNull($sacrament->fresh()->deleted_at);
        $this->assertDatabaseHas('sacrament_audit_logs', [
            'event' => 'void',
            'target_id' => (string) $sacrament->id,
        ]);
    }

    #[Test]
    public function restore_does_not_unvoid(): void
    {
        $sacrament = $this->createRegisteredSacrament();

        $this->postJson("/api/sacraments/{$sacrament->id}/void", [
            'lock_version' => 0,
            'reason' => 'Void first',
        ])->assertOk();

        $voided = $sacrament->fresh();
        $this->assertSame(SacramentStatus::VOIDED, $voided->status);
        $lockAfterVoid = (int) $voided->lock_version;

        $this->deleteJson("/api/sacraments/{$sacrament->id}")->assertOk();
        $this->assertSoftDeleted('sacraments', ['id' => $sacrament->id]);

        $restore = $this->postJson("/api/sacraments/{$sacrament->id}/restore");
        $restore->assertOk()
            ->assertJsonPath('data.status', SacramentStatus::VOIDED);

        $restored = $sacrament->fresh();
        $this->assertNull($restored->deleted_at);
        $this->assertSame(SacramentStatus::VOIDED, $restored->status);
        // Restore must not reinstate to registered.
        $this->assertNotSame(SacramentStatus::REGISTERED, $restored->status);
        $this->assertSame($lockAfterVoid, (int) $restored->lock_version);

        $this->assertDatabaseHas('sacrament_audit_logs', [
            'event' => 'restore',
            'target_id' => (string) $sacrament->id,
        ]);
    }

    #[Test]
    public function it_returns_409_on_stale_lock_version(): void
    {
        $sacrament = $this->createRegisteredSacrament(['lock_version' => 2]);

        $this->postJson("/api/sacraments/{$sacrament->id}/void", [
            'lock_version' => 0,
            'reason' => 'Stale attempt',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'record_version_conflict');

        $this->postJson("/api/sacraments/{$sacrament->id}/correct", [
            'lock_version' => 1,
            'reason' => 'Stale correct',
            'notes' => 'x',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'record_version_conflict');

        $this->patchJson("/api/sacraments/{$sacrament->id}/metadata", [
            'lock_version' => 0,
            'notes' => 'stale',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'record_version_conflict');
    }

    #[Test]
    public function it_patches_metadata_with_lock_version(): void
    {
        $sacrament = $this->createRegisteredSacrament(['notes' => 'old']);

        $this->patchJson("/api/sacraments/{$sacrament->id}/metadata", [
            'lock_version' => 0,
            'notes' => 'registry note',
            'book_number' => 'B-1',
            'registry_entry' => '12',
        ])
            ->assertOk()
            ->assertJsonPath('data.notes', 'registry note')
            ->assertJsonPath('data.book_number', 'B-1')
            ->assertJsonPath('data.lock_version', 1);
    }

    #[Test]
    public function it_requires_reason_for_correct_and_void(): void
    {
        $sacrament = $this->createRegisteredSacrament();

        $this->postJson("/api/sacraments/{$sacrament->id}/correct", [
            'lock_version' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $this->postJson("/api/sacraments/{$sacrament->id}/void", [
            'lock_version' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    }
}
