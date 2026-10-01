<?php

namespace Modules\Family\Tests\Unit;

use Modules\Family\Services\OccupationClassificationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OccupationClassificationServiceTest extends TestCase
{
    #[Test]
    #[DataProvider('classifications')]
    public function it_assigns_exactly_one_executive_category(string $occupation, string $category): void
    {
        $this->assertSame($category, OccupationClassificationService::classify($occupation));
        $this->assertArrayHasKey($category, array_column(OccupationClassificationService::categories(), 'label', 'key'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function classifications(): array
    {
        return [
            'student' => ['Student', 'student'],
            'students' => ['Students', 'student'],
            'engineering student' => ['Engineering Student', 'student'],
            'government servant' => ['Government servant', 'government_job'],
            'govt employee' => ['Govt Employee', 'government_job'],
            'public sector' => ['Public sector employee', 'government_job'],
            'government engineer' => ['Government Engineer', 'government_job'],
            'government software engineer' => ['Government Software Engineer', 'government_job'],
            'government doctor' => ['Government Doctor', 'government_job'],
            'government teacher' => ['Government Teacher', 'government_job'],
            'government school teacher' => ['Government School Teacher', 'government_job'],
            'software engineer' => ['Software engineer', 'professional'],
            'software developer' => ['Software Developer', 'professional'],
            'doctor' => ['Doctor', 'professional'],
            'lawyer' => ['Lawyer', 'professional'],
            'advocate' => ['Advocate', 'professional'],
            'civil engineer' => ['Civil engineer', 'professional'],
            'pharmacist' => ['Pharmacist', 'professional'],
            'nurse' => ['Nurse', 'professional'],
            'chartered accountant' => ['Chartered Accountant', 'professional'],
            'private engineer' => ['Private Engineer', 'professional'],
            'private doctor' => ['Private Hospital Doctor', 'professional'],
            'private software engineer' => ['Private Software Engineer', 'professional'],
            'shop owner' => ['Shop owner', 'business_self_employed'],
            'business owner' => ['Business owner', 'business_self_employed'],
            'trader' => ['Trader', 'business_self_employed'],
            'self employed' => ['Self-employed', 'business_self_employed'],
            'electrician' => ['Electrician', 'labour_skilled_trade'],
            'plumber' => ['Plumber', 'labour_skilled_trade'],
            'carpenter' => ['Carpenter', 'labour_skilled_trade'],
            'mechanic' => ['Mechanic', 'labour_skilled_trade'],
            'auto driver' => ['Auto driver', 'labour_skilled_trade'],
            'fisherman' => ['Fisherman', 'labour_skilled_trade'],
            'tailor' => ['Tailor', 'labour_skilled_trade'],
            'parish secretary' => ['Parish secretary', 'unclassified'],
            'catechist' => ['Catechist', 'unclassified'],
            'retired employee' => ['Retired employee', 'former_retired'],
            'retired government teacher' => ['Retired Government Teacher', 'former_retired'],
            'pensioner' => ['Pensioner', 'former_retired'],
            'former government servant' => ['Former government servant', 'former_retired'],
            'private teacher' => ['Private Teacher', 'private_job'],
            'private school teacher' => ['Private School Teacher', 'private_job'],
            'private accountant' => ['Private accountant', 'private_job'],
            'it employee' => ['IT employee', 'private_job'],
            'sales executive' => ['Sales executive', 'private_job'],
            'homemaker' => ['Homemaker', 'homemaker'],
            'housewife' => ['Housewife', 'homemaker'],
            'home maker' => ['Home maker', 'homemaker'],
            'accountant' => ['Accountant', 'unclassified'],
            'school teacher' => ['School teacher', 'unclassified'],
            'bank clerk' => ['Bank clerk', 'unclassified'],
            'engineering lecturer' => ['Engineering College Lecturer', 'unclassified'],
            'unknown' => ['Mystery role', 'unclassified'],
            'qualification abbreviation' => ['B.E', 'unclassified'],
        ];
    }

    #[Test]
    public function blank_occupation_is_not_recorded_and_spacing_does_not_split_a_member(): void
    {
        $this->assertSame('not_recorded', OccupationClassificationService::classify(null));
        $this->assertSame('not_recorded', OccupationClassificationService::classify('   '));
        $this->assertSame(
            'professional',
            OccupationClassificationService::classify('  Software   Engineer ')
        );
        $this->assertSame(
            OccupationClassificationService::classify('Parish secretary'),
            OccupationClassificationService::classify('  PARISH   SECRETARY ')
        );
    }

    #[Test]
    public function ambiguous_titles_keep_a_documented_reason(): void
    {
        $accountant = OccupationClassificationService::explain('Accountant');
        $this->assertSame('unclassified', $accountant['category']);
        $this->assertStringContainsString('Chartered Accountant', $accountant['reason']);

        $teacher = OccupationClassificationService::explain('School teacher');
        $this->assertSame('unclassified', $teacher['category']);
        $this->assertStringContainsString('without government', $teacher['reason']);

        $clerk = OccupationClassificationService::explain('Bank clerk');
        $this->assertSame('unclassified', $clerk['category']);
        $this->assertStringContainsString('public-sector', $clerk['reason']);
    }

    #[Test]
    public function grouped_counts_reconcile_to_the_population(): void
    {
        $rows = [
            (object) ['value_key' => 'student', 'aggregate' => 2],
            (object) ['value_key' => 'software engineer', 'aggregate' => 2],
            (object) ['value_key' => 'accountant', 'aggregate' => 1],
        ];

        $aggregated = OccupationClassificationService::aggregateGrouped($rows, 3);

        $this->assertSame(8, $aggregated['total']);
        $this->assertSame(5, $aggregated['recorded']);
        $this->assertSame(3, $aggregated['not_recorded']);
        $this->assertSame(1, $aggregated['unclassified']);
        $this->assertSame(8, collect($aggregated['values'])->sum('count'));
        $this->assertSame(10, count($aggregated['categories']));
        $this->assertNotContains('software engineer', collect($aggregated['values'])->pluck('key'));
    }
}
