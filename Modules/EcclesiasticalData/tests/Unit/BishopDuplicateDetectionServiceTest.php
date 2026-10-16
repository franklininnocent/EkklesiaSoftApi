<?php

namespace Modules\EcclesiasticalData\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Models\DioceseManagement;
use Modules\EcclesiasticalData\Services\BishopDuplicateDetectionService;
use Modules\EcclesiasticalData\Services\BishopService;
use Modules\EcclesiasticalData\Support\BishopNameNormalizer;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\Denomination;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * @group ecclesiastical
 * @group bishop-create-modal
 * @group unit
 */
class BishopDuplicateDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private BishopDuplicateDetectionService $service;

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

        $this->service = app(BishopDuplicateDetectionService::class);
    }

    #[Test]
    public function it_returns_no_duplicates_for_blank_name(): void
    {
        $this->assertTrue(
            $this->service->findHighConfidenceDuplicates(['full_name' => ''])->isEmpty()
        );
    }

    #[Test]
    public function it_detects_high_confidence_duplicate_with_matching_dates(): void
    {
        $diocese = DioceseManagement::factory()->create();

        app(BishopService::class)->createPerson([
            'full_name' => 'Most Rev. John Smith',
            'date_of_birth' => '1960-05-10',
            'ordained_priest_date' => '1985-06-01',
            'ordained_bishop_date' => '2010-09-01',
            'archdiocese_id' => $diocese->id,
        ], null, false);

        $duplicates = $this->service->findHighConfidenceDuplicates([
            'full_name' => 'Most Rev. John Smith',
            'date_of_birth' => '1960-05-10',
            'ordained_priest_date' => '1985-06-01',
            'ordained_bishop_date' => '2010-09-01',
        ]);

        $this->assertCount(1, $duplicates);
    }

    #[Test]
    public function it_treats_same_name_with_different_birth_date_as_low_confidence(): void
    {
        $diocese = DioceseManagement::factory()->create();

        BishopManagement::factory()->create([
            'full_name' => 'Most Rev. John Smith',
            'date_of_birth' => '1960-05-10',
            'archdiocese_id' => $diocese->id,
        ]);

        $duplicates = $this->service->findHighConfidenceDuplicates([
            'full_name' => 'Most Rev. John Smith',
            'date_of_birth' => '1970-01-01',
        ]);

        $this->assertTrue($duplicates->isEmpty());
    }

    #[Test]
    public function it_normalizes_titles_before_matching(): void
    {
        $diocese = DioceseManagement::factory()->create();

        app(BishopService::class)->createPerson([
            'full_name' => 'John Smith',
            'date_of_birth' => '1960-05-10',
            'ordained_priest_date' => '1985-06-01',
            'ordained_bishop_date' => '2010-09-01',
            'archdiocese_id' => $diocese->id,
        ], null, false);

        $duplicates = $this->service->findHighConfidenceDuplicates([
            'full_name' => 'Most Rev. John Smith',
            'date_of_birth' => '1960-05-10',
            'ordained_priest_date' => '1985-06-01',
            'ordained_bishop_date' => '2010-09-01',
        ]);

        $this->assertCount(1, $duplicates);
    }

    #[Test]
    public function it_excludes_specified_bishop_id_from_duplicate_search(): void
    {
        $diocese = DioceseManagement::factory()->create();

        $bishop = BishopManagement::factory()->create([
            'full_name' => 'Most Rev. John Smith',
            'date_of_birth' => '1960-05-10',
            'ordained_priest_date' => '1985-06-01',
            'ordained_bishop_date' => '2010-09-01',
            'archdiocese_id' => $diocese->id,
        ]);

        $duplicates = $this->service->findHighConfidenceDuplicates([
            'full_name' => 'Most Rev. John Smith',
            'date_of_birth' => '1960-05-10',
            'ordained_priest_date' => '1985-06-01',
            'ordained_bishop_date' => '2010-09-01',
        ], $bishop->id);

        $this->assertTrue($duplicates->isEmpty());
    }
}
