<?php

namespace Modules\Sacraments\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\Sacraments\Models\SacramentParticipant;
use Modules\Sacraments\Services\Dashboard\SacramentDashboardRecipientResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SacramentDashboardRecipientResolverTest extends TestCase
{
    use RefreshDatabase;

    private SacramentDashboardRecipientResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new SacramentDashboardRecipientResolver;
    }

    #[Test]
    public function it_calculates_completed_years_before_birthday_in_marriage_year(): void
    {
        $age = $this->resolver->ageAtSacrament(
            Carbon::parse('2000-10-15'),
            Carbon::parse('2026-09-04')
        );

        $this->assertSame(25.0, $age);
    }

    #[Test]
    public function it_calculates_completed_years_on_birthday(): void
    {
        $age = $this->resolver->ageAtSacrament(
            Carbon::parse('2000-10-15'),
            Carbon::parse('2026-10-15')
        );

        $this->assertSame(26.0, $age);
    }

    #[Test]
    public function it_handles_leap_year_date_of_birth(): void
    {
        $age = $this->resolver->ageAtSacrament(
            Carbon::parse('2000-02-29'),
            Carbon::parse('2024-02-28')
        );

        $this->assertSame(23.0, $age);
    }

    #[Test]
    public function it_returns_null_when_birth_date_is_missing(): void
    {
        $this->assertNull($this->resolver->ageAtSacrament(null, Carbon::parse('2025-01-01')));
    }

    #[Test]
    public function it_returns_null_when_marriage_date_is_missing(): void
    {
        $this->assertNull($this->resolver->ageAtSacrament(Carbon::parse('1990-01-01'), null));
    }

    #[Test]
    public function it_returns_null_when_birth_date_is_after_marriage_date(): void
    {
        $this->assertNull($this->resolver->ageAtSacrament(
            Carbon::parse('2000-01-01'),
            Carbon::parse('1999-12-31')
        ));
    }

    #[Test]
    public function it_returns_null_when_birth_date_is_in_the_future(): void
    {
        $future = Carbon::today()->addYear();

        $this->assertNull($this->resolver->ageAtSacrament(
            $future,
            Carbon::today()->addYears(2)
        ));
    }

    #[Test]
    public function it_resolves_participant_dob_from_snapshot_json(): void
    {
        $participant = new SacramentParticipant([
            'external_date_of_birth' => null,
            'snapshot_json' => ['date_of_birth' => '1992-06-18'],
        ]);

        $birthDate = $this->resolver->resolveParticipantBirthDate($participant);

        $this->assertNotNull($birthDate);
        $this->assertSame('1992-06-18', $birthDate->format('Y-m-d'));
    }

    #[Test]
    public function it_resolves_participant_dob_from_linked_person_when_member_dob_is_null(): void
    {
        $person = Person::factory()->make([
            'date_of_birth' => '1991-04-22',
        ]);
        $member = FamilyMember::factory()->make([
            'date_of_birth' => null,
        ]);
        $member->setRelation('person', $person);

        $participant = new SacramentParticipant([
            'role' => 'bride',
            'source' => 'member',
            'external_date_of_birth' => null,
            'snapshot_json' => [],
        ]);
        $participant->setRelation('familyMember', $member);

        $birthDate = $this->resolver->resolveParticipantBirthDate($participant);

        $this->assertNotNull($birthDate);
        $this->assertSame('1991-04-22', $birthDate->format('Y-m-d'));
    }

    #[Test]
    public function it_resolves_participant_dob_from_family_member_date_of_birth(): void
    {
        $member = FamilyMember::factory()->make([
            'date_of_birth' => '1993-08-05',
        ]);

        $participant = new SacramentParticipant([
            'role' => 'groom',
            'source' => 'member',
            'external_date_of_birth' => null,
            'snapshot_json' => [],
        ]);
        $participant->setRelation('familyMember', $member);

        $birthDate = $this->resolver->resolveParticipantBirthDate($participant);

        $this->assertNotNull($birthDate);
        $this->assertSame('1993-08-05', $birthDate->format('Y-m-d'));
    }
}
