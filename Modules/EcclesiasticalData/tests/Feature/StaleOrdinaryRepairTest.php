<?php

namespace Modules\EcclesiasticalData\Tests\Feature;

use Illuminate\Support\Str;
use Modules\EcclesiasticalData\Database\Seeders\EcclesiasticalOfficesSeeder;
use Modules\EcclesiasticalData\Models\DioceseManagement;
use Modules\EcclesiasticalData\Services\BishopService;
use Modules\EcclesiasticalData\Services\DioceseLeadershipQueryService;
use Modules\EcclesiasticalData\Services\SuccessionService;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\Denomination;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Testing\ChurchProfileCertificationTestCase;
use PHPUnit\Framework\Attributes\Test;

class StaleOrdinaryRepairTest extends ChurchProfileCertificationTestCase
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

        $this->seed(EcclesiasticalOfficesSeeder::class);
    }

    #[Test]
    public function it_repairs_retired_ordinary_with_active_successor_on_leadership_query(): void
    {
        $ctx = $this->actingAsTenantWith(['bishops.view', 'church.settings.view']);
        $diocese = DioceseManagement::factory()->create();

        $former = app(BishopService::class)->createPerson([
            'full_name' => 'Bishop Former',
            'archdiocese_id' => $diocese->id,
            'status' => 'active',
        ], null, false);

        app(SuccessionService::class)->replaceCurrentOrdinary(
            (int) $diocese->id,
            $former,
            ['effective_date' => '2018-12-08'],
        );

        \DB::table('bishops')->where('id', $former->id)->update([
            'status' => 'retired',
            'is_current' => false,
        ]);

        $successor = app(BishopService::class)->createPerson([
            'full_name' => 'Bishop Successor',
            'archdiocese_id' => $diocese->id,
            'status' => 'active',
            'appointed_date' => '2024-01-13',
        ], null, false);

        ChurchProfile::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'archdiocese_id' => $diocese->id,
            'bishop_id' => $former->id,
        ]);

        $leadership = app(DioceseLeadershipQueryService::class)->getCurrentLeadership((int) $diocese->id);

        $this->assertSame('Bishop Successor', $leadership['ordinary']['bishop_name']);

        $this->getJson('/api/church-profile/leadership/diocesan')
            ->assertOk()
            ->assertJsonPath('data.ordinary.bishop_name', 'Bishop Successor');

        $this->getJson('/api/church-profile')
            ->assertOk()
            ->assertJsonPath('data.bishop.full_name', 'Bishop Successor');
    }

    #[Test]
    public function it_ends_current_appointments_when_bishop_is_marked_retired(): void
    {
        $diocese = DioceseManagement::factory()->create();
        $bishop = app(BishopService::class)->createPerson([
            'full_name' => 'Bishop To Retire',
            'archdiocese_id' => $diocese->id,
            'status' => 'active',
        ], null, false);

        app(SuccessionService::class)->replaceCurrentOrdinary(
            (int) $diocese->id,
            $bishop,
            ['effective_date' => '2018-01-01'],
        );

        app(BishopService::class)->updatePersonRecord($bishop, ['status' => 'retired']);

        $this->assertDatabaseMissing('bishop_appointments', [
            'bishop_id' => $bishop->id,
            'is_current' => true,
        ]);
    }
}
