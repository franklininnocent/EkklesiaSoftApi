<?php

namespace Modules\Sacraments\Tests\Unit;

use Modules\Sacraments\Services\Context\ConflictDetector;
use Modules\Sacraments\Services\Context\DerivedFactResolver;
use Modules\Sacraments\Services\Context\ProvenanceBuilder;
use Modules\Sacraments\Support\BaptismalStatus;
use Modules\Sacraments\Support\SacramentConflictSeverity;
use Modules\Sacraments\Support\SacramentRecordStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SacramentContextResolverUnitTest extends TestCase
{
    #[Test]
    public function derived_fact_resolver_does_not_set_unbaptized_when_not_found(): void
    {
        $resolver = new DerivedFactResolver(new ProvenanceBuilder);
        $result = $resolver->resolveBaptismalStatus([
            'record_status' => SacramentRecordStatus::NOT_FOUND,
        ]);

        $this->assertNull($result['value']);
        $this->assertSame('MISSING', $result['field_state']);
    }

    #[Test]
    public function derived_fact_resolver_sets_baptized_catholic_when_baptism_found(): void
    {
        $resolver = new DerivedFactResolver(new ProvenanceBuilder);
        $result = $resolver->resolveBaptismalStatus([
            'record_status' => SacramentRecordStatus::FOUND,
            'evidence_tier' => 'BAPTISM_RECORD',
            'evidence' => ['sacrament_id' => 1],
        ]);

        $this->assertSame(BaptismalStatus::BAPTIZED_CATHOLIC, $result['value']);
    }

    #[Test]
    public function derived_fact_resolver_sets_baptized_catholic_for_home_parish_profile_baptism(): void
    {
        $resolver = new DerivedFactResolver(new ProvenanceBuilder);
        $result = $resolver->resolveBaptismalStatus([
            'record_status' => SacramentRecordStatus::FOUND,
            'evidence_tier' => 'MEMBER_PROFILE',
            'evidence' => [
                'member_id' => 'member-1',
                'baptism_location_type' => 'home_parish',
            ],
        ]);

        $this->assertSame(BaptismalStatus::BAPTIZED_CATHOLIC, $result['value']);
        $this->assertSame('NOT_INDEPENDENTLY_VERIFIED', $result['provenance']['verification_status']);
    }

    #[Test]
    public function derived_fact_resolver_does_not_derive_status_for_other_church_profile_baptism(): void
    {
        $resolver = new DerivedFactResolver(new ProvenanceBuilder);
        $result = $resolver->resolveBaptismalStatus([
            'record_status' => SacramentRecordStatus::FOUND,
            'evidence_tier' => 'MEMBER_PROFILE',
            'evidence' => [
                'member_id' => 'member-1',
                'baptism_location_type' => 'other',
            ],
        ]);

        $this->assertNull($result['value']);
    }

    #[Test]
    public function conflict_detector_flags_dob_mismatch_as_review_required(): void
    {
        $detector = new ConflictDetector;
        $conflicts = $detector->detect(
            [
                'date_of_birth' => ['value' => '2000-03-01', 'provenance' => ['source_type' => 'PERSON_PROFILE', 'source_label' => 'Person Profile']],
            ],
            [
                'record_status' => SacramentRecordStatus::FOUND,
                'evidence' => [
                    'recipient_birth_date' => '2000-03-02',
                ],
            ]
        );

        $this->assertCount(1, $conflicts);
        $this->assertSame('date_of_birth', $conflicts[0]['field']);
        $this->assertSame(SacramentConflictSeverity::REVIEW_REQUIRED, $conflicts[0]['severity']);
        $this->assertTrue($conflicts[0]['blocks_save']);
    }
}
