<?php

namespace Modules\Sacraments\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentParticipant;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Support\SacramentFeatureFlags;
use Modules\Sacraments\Support\SacramentParticipantRole;
use Modules\Sacraments\Support\SacramentParticipantSource;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 2 — schema expansion smoke tests (no API behavior change).
 */
class SacramentPhase2SchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_phase2_tables(): void
    {
        $this->assertTrue(Schema::hasTable('sacrament_participants'));
        $this->assertTrue(Schema::hasColumn('sacrament_participants', 'external_contact_number'));
        $this->assertTrue(Schema::hasTable('sacrament_certificates'));
        $this->assertTrue(Schema::hasTable('sacrament_migration_resolutions'));
        $this->assertTrue(Schema::hasTable('sacrament_idempotency_keys'));
    }

    #[Test]
    public function it_adds_lock_version_and_diocese_columns_on_sacraments(): void
    {
        $this->assertTrue(Schema::hasColumn('sacraments', 'lock_version'));
        $this->assertTrue(Schema::hasColumn('sacraments', 'registry_entry'));
        $this->assertTrue(Schema::hasColumn('sacraments', 'marriage_bride_diocese_name'));
        $this->assertTrue(Schema::hasColumn('sacraments', 'marriage_groom_diocese_name'));
    }

    #[Test]
    public function participants_v1_feature_flag_is_off_by_default(): void
    {
        $this->assertFalse(config('sacraments.participants_v1'));
        $this->assertFalse(SacramentFeatureFlags::participantsV1Enabled());
    }

    #[Test]
    public function it_can_persist_a_participant_row_without_affecting_legacy_create_path(): void
    {
        $tenant = Tenant::factory()->create();
        $type = SacramentType::factory()->create();
        $sacrament = Sacrament::factory()->create([
            'tenant_id' => $tenant->id,
            'sacrament_type_id' => $type->id,
            'lock_version' => 0,
        ]);

        $participant = SacramentParticipant::create([
            'tenant_id' => $tenant->id,
            'sacrament_id' => $sacrament->id,
            'role' => SacramentParticipantRole::RECIPIENT,
            'source' => SacramentParticipantSource::EXTERNAL,
            'external_full_name' => 'Jane Doe',
            'sort_order' => 0,
            'snapshot_json' => ['full_name' => 'Jane Doe'],
        ]);

        $this->assertDatabaseHas('sacrament_participants', [
            'id' => $participant->id,
            'sacrament_id' => $sacrament->id,
            'role' => 'recipient',
            'source' => 'external',
        ]);

        $this->assertSame(0, $sacrament->fresh()->lock_version);
        $this->assertCount(1, $sacrament->participants);
    }
}
