<?php

namespace Modules\Sacraments\Tests\Unit;

use Modules\Sacraments\Services\MarriageCanonicalClassifier;
use Modules\Sacraments\Support\BaptismalStatus;
use Modules\Sacraments\Support\MarriageCanonicalClassification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MarriageCanonicalClassifierTest extends TestCase
{
    #[Test]
    public function it_derives_classification_from_baptismal_status(): void
    {
        $classifier = new MarriageCanonicalClassifier;

        $this->assertSame(
            MarriageCanonicalClassification::BOTH_CATHOLIC,
            $classifier->derive(BaptismalStatus::BAPTIZED_CATHOLIC, BaptismalStatus::BAPTIZED_CATHOLIC)
        );
        $this->assertSame(
            MarriageCanonicalClassification::MIXED_MARRIAGE,
            $classifier->derive(BaptismalStatus::BAPTIZED_CATHOLIC, BaptismalStatus::BAPTIZED_NON_CATHOLIC)
        );
        $this->assertSame(
            MarriageCanonicalClassification::DISPARITY_OF_CULT,
            $classifier->derive(BaptismalStatus::BAPTIZED_CATHOLIC, BaptismalStatus::UNBAPTIZED)
        );
        $this->assertSame(
            MarriageCanonicalClassification::OTHER,
            $classifier->derive(BaptismalStatus::UNBAPTIZED, BaptismalStatus::UNBAPTIZED)
        );
        $this->assertNull($classifier->derive(null, BaptismalStatus::BAPTIZED_CATHOLIC));
        $this->assertTrue(MarriageCanonicalClassification::requiresDispensation(
            MarriageCanonicalClassification::MIXED_MARRIAGE
        ));
        $this->assertFalse(MarriageCanonicalClassification::requiresDispensation(
            MarriageCanonicalClassification::BOTH_CATHOLIC
        ));
    }
}
