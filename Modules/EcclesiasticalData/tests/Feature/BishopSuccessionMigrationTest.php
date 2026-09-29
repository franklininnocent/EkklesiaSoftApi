<?php

namespace Modules\EcclesiasticalData\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\EcclesiasticalData\Models\BishopAppointment;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Models\BishopUpdateRequest;
use Modules\EcclesiasticalData\Models\DioceseManagement;
use Modules\EcclesiasticalData\Services\BishopAppointmentBackfillService;
use Modules\EcclesiasticalData\Support\BishopUpdateRequestStatus;
use Modules\EcclesiasticalData\Support\BishopUpdateRequestType;
use Modules\EcclesiasticalData\Support\CanonicalRole;
use Modules\EcclesiasticalData\Support\DioceseLeadershipState;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\Denomination;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * @group ecclesiastical
 * @group bishop-succession
 */
class BishopSuccessionMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected DioceseManagement $diocese;

    protected BishopAppointmentBackfillService $backfillService;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Denomination::count()) {
            Denomination::create([
                'name' => 'Roman Catholic',
                'code' => 'RC',
                'description' => 'Roman Catholic Church',
                'status' => 'active',
            ]);
        }

        if (! Country::where('iso2', 'IN')->exists()) {
            Country::create([
                'name' => 'India',
                'iso2' => 'IN',
                'iso3' => 'IND',
                'phone_code' => '+91',
                'status' => 'active',
            ]);
        }

        $this->backfillService = app(BishopAppointmentBackfillService::class);
        $this->diocese = DioceseManagement::factory()->create();
    }

    #[Test]
    public function it_should_backfill_bishop_appointments_from_legacy_bishop_rows(): void
    {
        $bishop = BishopManagement::factory()->create([
            'archdiocese_id' => $this->diocese->id,
            'full_name' => 'Most Rev. John Paul Example',
            'appointed_date' => '2020-01-15',
            'is_current' => true,
            'status' => 'active',
        ]);

        $this->backfillService->backfillMissingAppointments();

        $appointment = BishopAppointment::query()
            ->where('bishop_id', $bishop->id)
            ->where('diocese_id', $this->diocese->id)
            ->first();

        $this->assertNotNull($appointment);
        $this->assertTrue($appointment->is_current);
        $this->assertEquals(CanonicalRole::DiocesanBishop, $appointment->canonical_role);
        $this->assertEquals('2020-01-15', $appointment->effective_date?->format('Y-m-d'));
        $this->assertEquals('john paul example', $bishop->fresh()->normalized_name);
    }

    #[Test]
    public function it_should_set_diocese_leadership_state_after_backfill(): void
    {
        BishopManagement::factory()->create([
            'archdiocese_id' => $this->diocese->id,
            'is_current' => true,
            'status' => 'active',
        ]);

        $this->backfillService->backfillMissingAppointments();

        $this->assertEquals(
            DioceseLeadershipState::Occupied,
            $this->diocese->fresh()->leadership_state
        );
    }

    #[Test]
    public function it_should_mark_diocese_vacant_when_no_current_ordinary_exists(): void
    {
        $vacantDiocese = DioceseManagement::factory()->create();

        $this->backfillService->refreshDioceseLeadershipStates();

        $this->assertEquals(
            DioceseLeadershipState::Vacant,
            $vacantDiocese->fresh()->leadership_state
        );
    }

    #[Test]
    public function it_should_enforce_one_current_ordinary_per_diocese(): void
    {
        $bishopA = BishopManagement::factory()->create([
            'archdiocese_id' => $this->diocese->id,
            'is_current' => true,
        ]);

        $bishopB = BishopManagement::factory()->create([
            'archdiocese_id' => $this->diocese->id,
            'is_current' => false,
        ]);

        $this->backfillService->backfillMissingAppointments();

        $this->assertDatabaseHas('bishop_appointments', [
            'bishop_id' => $bishopA->id,
            'diocese_id' => $this->diocese->id,
            'is_current' => 1,
            'canonical_role' => CanonicalRole::DiocesanBishop->value,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        BishopAppointment::create([
            'id' => (string) Str::uuid(),
            'bishop_id' => $bishopB->id,
            'diocese_id' => $this->diocese->id,
            'ecclesiastical_title_id' => null,
            'canonical_role' => CanonicalRole::DiocesanBishop->value,
            'appointed_date' => '2026-01-01',
            'effective_date' => '2026-01-01',
            'is_current' => true,
            'appointment_status' => 'current',
        ]);
    }

    #[Test]
    public function it_should_allow_auxiliary_bishop_alongside_current_ordinary(): void
    {
        $ordinary = BishopManagement::factory()->create([
            'archdiocese_id' => $this->diocese->id,
            'is_current' => true,
        ]);

        $this->backfillService->backfillMissingAppointments();

        $auxiliary = BishopManagement::factory()->create([
            'archdiocese_id' => $this->diocese->id,
            'is_current' => true,
            'precedence_order' => 3,
        ]);

        BishopAppointment::create([
            'id' => (string) Str::uuid(),
            'bishop_id' => $auxiliary->id,
            'diocese_id' => $this->diocese->id,
            'ecclesiastical_title_id' => null,
            'canonical_role' => CanonicalRole::Auxiliary->value,
            'appointed_date' => '2024-06-01',
            'effective_date' => '2024-06-01',
            'is_current' => true,
            'appointment_status' => 'current',
        ]);

        $this->assertDatabaseHas('bishop_appointments', [
            'bishop_id' => $ordinary->id,
            'canonical_role' => CanonicalRole::DiocesanBishop->value,
            'is_current' => 1,
        ]);

        $this->assertDatabaseHas('bishop_appointments', [
            'bishop_id' => $auxiliary->id,
            'canonical_role' => CanonicalRole::Auxiliary->value,
            'is_current' => 1,
        ]);
    }

    #[Test]
    public function it_should_persist_bishop_update_requests_with_tenant_scope(): void
    {
        $tenant = Tenant::factory()->create();

        $request = BishopUpdateRequest::create([
            'tenant_id' => $tenant->id,
            'diocese_id' => $this->diocese->id,
            'request_type' => BishopUpdateRequestType::ChangeCurrentBishop,
            'proposed_bishop_data' => ['full_name' => 'Bishop Example'],
            'proposed_appointment_data' => ['effective_date' => '2026-03-01'],
            'status' => BishopUpdateRequestStatus::Draft,
        ]);

        $this->assertDatabaseHas('bishop_update_requests', [
            'id' => $request->id,
            'tenant_id' => $tenant->id,
            'status' => BishopUpdateRequestStatus::Draft->value,
        ]);
    }

    #[Test]
    public function it_should_have_bishop_update_request_indexes(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('bishop_update_requests'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumns('bishop_appointments', [
            'canonical_role',
            'effective_date',
            'appointment_status',
            'version',
        ]));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumns('archdioceses', [
            'leadership_state',
            'last_verified_at',
        ]));
    }
}
