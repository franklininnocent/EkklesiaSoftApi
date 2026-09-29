<?php

namespace Modules\Tenants\Tests\Feature\ChurchProfile;

use Illuminate\Support\Str;
use Modules\EcclesiasticalData\Models\DioceseManagement;
use Modules\EcclesiasticalData\Services\BishopService;
use Modules\EcclesiasticalData\Services\SuccessionService;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\Denomination;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Testing\ChurchProfileCertificationTestCase;
use PHPUnit\Framework\Attributes\Test;

class ChurchProfileBishopSyncTest extends ChurchProfileCertificationTestCase
{
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
    }

    #[Test]
    public function it_should_ignore_stale_bishop_id_override_when_resolving_current_ordinary(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);
        $diocese = DioceseManagement::factory()->create();

        $formerId = \DB::table('bishops')->insertGetId([
            'full_name' => 'Former Bishop',
            'archdiocese_id' => $diocese->id,
            'status' => 'retired',
            'is_current' => false,
            'precedence_order' => 1,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $currentId = \DB::table('bishops')->insertGetId([
            'full_name' => 'Current Bishop',
            'archdiocese_id' => $diocese->id,
            'status' => 'active',
            'is_current' => true,
            'precedence_order' => 1,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedAppointment($formerId, $diocese->id, '2018-01-01', '2025-12-31', false);
        $this->seedAppointment($currentId, $diocese->id, '2026-01-01', null, true);

        ChurchProfile::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'archdiocese_id' => $diocese->id,
            'bishop_id' => $formerId,
        ]);

        $response = $this->getJson('/api/church-profile')->assertOk();

        $response->assertJsonPath('data.bishop.id', $currentId);
        $response->assertJsonPath('data.bishop.full_name', 'Current Bishop');
    }

    #[Test]
    public function it_should_update_stale_church_profile_bishop_id_after_succession(): void
    {
        $diocese = DioceseManagement::factory()->create();
        $tenant = Tenant::factory()->create();

        $former = app(BishopService::class)->createPerson([
            'full_name' => 'Bishop Former',
            'archdiocese_id' => $diocese->id,
            'status' => 'active',
        ], null, false);

        app(SuccessionService::class)->replaceCurrentOrdinary(
            (int) $diocese->id,
            $former,
            ['effective_date' => '2018-01-01'],
        );

        $profile = ChurchProfile::query()->create([
            'tenant_id' => $tenant->id,
            'archdiocese_id' => $diocese->id,
            'bishop_id' => $former->id,
        ]);

        $successor = app(BishopService::class)->createPerson([
            'full_name' => 'Bishop Successor',
            'archdiocese_id' => $diocese->id,
            'status' => 'active',
        ], null, false);

        app(SuccessionService::class)->replaceCurrentOrdinary(
            (int) $diocese->id,
            $successor,
            ['effective_date' => '2026-01-01'],
        );

        $this->assertEquals($successor->id, $profile->fresh()->bishop_id);
    }

    private function seedAppointment(
        int $bishopId,
        int $dioceseId,
        string $effectiveDate,
        ?string $endedDate,
        bool $isCurrent,
    ): void {
        \DB::table('bishop_appointments')->insert([
            'id' => (string) Str::uuid(),
            'bishop_id' => $bishopId,
            'diocese_id' => $dioceseId,
            'appointed_date' => $effectiveDate,
            'effective_date' => $effectiveDate,
            'ended_date' => $endedDate,
            'canonical_role' => 'diocesan_bishop',
            'appointment_status' => $isCurrent ? 'current' : 'ended',
            'is_current' => $isCurrent,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
