<?php

namespace Modules\Family\Tests\Unit;

use Modules\Family\Support\MemberEducationQualification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberEducationQualificationTest extends TestCase
{
    #[Test]
    public function it_maps_explicit_qualifications_to_consolidated_levels(): void
    {
        $this->assertSame('pg', MemberEducationQualification::classify('B.Ed'));
        $this->assertSame('pg', MemberEducationQualification::classify('MBA'));
        $this->assertSame('professional', MemberEducationQualification::classify('M.Tech'));
        $this->assertSame('professional', MemberEducationQualification::classify('M.Tech Computer Science'));
        $this->assertSame('school', MemberEducationQualification::classify('Plus Two Science'));
        $this->assertSame('school', MemberEducationQualification::classify('Plus Two — Science'));
        $this->assertSame('school', MemberEducationQualification::classify('Plus Two — Commerce'));
        $this->assertSame('iti', MemberEducationQualification::classify('ITI Electrician'));
        $this->assertSame('iti', MemberEducationQualification::classify('ITI — Electrician'));
        $this->assertSame('iti', MemberEducationQualification::classify('ITI — Fitter'));
        $this->assertSame('diploma', MemberEducationQualification::classify('Diploma Engineering'));
        $this->assertSame('diploma', MemberEducationQualification::classify('Diploma in Nursing'));
        $this->assertSame('professional', MemberEducationQualification::classify('B.Tech'));
        $this->assertSame('professional', MemberEducationQualification::classify('B.Tech — Computer Science'));
        $this->assertSame('professional', MemberEducationQualification::classify('B.E.'));
        $this->assertSame('professional', MemberEducationQualification::classify('B.E. Mechanical'));
        $this->assertSame('ug', MemberEducationQualification::classify('B.Com'));
        $this->assertSame('ug', MemberEducationQualification::classify('B.Com, Mahatma Gandhi University'));
        $this->assertSame('ug', MemberEducationQualification::classify('BBA'));
        $this->assertSame('ug', MemberEducationQualification::classify('BCA'));
        $this->assertSame('ug', MemberEducationQualification::classify('BA'));
        $this->assertSame('ug', MemberEducationQualification::classify('BA English Literature'));
        $this->assertSame('ug', MemberEducationQualification::classify('B.Sc'));
        $this->assertSame('pg', MemberEducationQualification::classify('MA'));
        $this->assertSame('pg', MemberEducationQualification::classify('M.Sc'));
        $this->assertSame('pg', MemberEducationQualification::classify('M.Com'));
        $this->assertSame('pg', MemberEducationQualification::classify('MCA'));

        $this->assertSame('unclassified', MemberEducationQualification::classify('Sacred Heart High School'));
        $this->assertSame('unclassified', MemberEducationQualification::classify('St. Mary\'s LP School'));
        $this->assertSame('unclassified', MemberEducationQualification::classify('Don Bosco HS'));
        $this->assertSame('unclassified', MemberEducationQualification::classify('Computer Science'));
        $this->assertSame('unclassified', MemberEducationQualification::classify('Electrician'));
        $this->assertSame('unclassified', MemberEducationQualification::classify('Science'));
        $this->assertSame('unclassified', MemberEducationQualification::classify('Bachelor of Science'));
        $this->assertSame('unclassified', MemberEducationQualification::classify('before'));
        $this->assertSame('unclassified', MemberEducationQualification::classify('management'));
        $this->assertSame('not_recorded', MemberEducationQualification::classify('   '));
        $this->assertSame('not_recorded', MemberEducationQualification::classify(null));
    }

    #[Test]
    public function it_counts_every_member_once_against_the_full_population(): void
    {
        $rows = [
            (object) ['value_key' => 'sacred heart high school', 'aggregate' => 50],
            (object) ['value_key' => 'b.tech — computer science', 'aggregate' => 10],
            (object) ['value_key' => 'b.ed', 'aggregate' => 4],
            (object) ['value_key' => 'mba', 'aggregate' => 2],
            (object) ['value_key' => 'm.tech', 'aggregate' => 3],
            (object) ['value_key' => 'plus two — science', 'aggregate' => 6],
            (object) ['value_key' => 'iti — electrician', 'aggregate' => 5],
            (object) ['value_key' => 'computer science', 'aggregate' => 1],
        ];

        $result = MemberEducationQualification::aggregateGrouped($rows, 7);
        $byLabel = collect($result['values'])->keyBy('label');

        $this->assertSame(88, $result['total']);
        $this->assertSame(7, $result['not_recorded']);
        $this->assertSame(51, $result['excluded']);
        $this->assertSame(30, $result['recorded']);
        $this->assertSame(88, collect($result['values'])->sum('count'));
        $this->assertSame(13, $byLabel['Professional Education']['count']);
        $this->assertSame(6, $byLabel['Postgraduate (PG)']['count']);
        $this->assertSame(6, $byLabel['School Education (Classes 1–12)']['count']);
        $this->assertSame(5, $byLabel['ITI / Vocational']['count']);
        $this->assertSame(51, $byLabel['Other / Unclassified']['count']);
        $this->assertSame(7, $byLabel['Not Recorded']['count']);
        $this->assertFalse($byLabel->has('Sacred Heart High School'));
        $this->assertFalse($byLabel->has('Computer Science'));
        $this->assertFalse($byLabel->has('Electrician'));
        $this->assertSame(14.8, $byLabel['Professional Education']['percent']);
    }
}
