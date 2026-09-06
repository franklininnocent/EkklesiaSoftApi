<?php

namespace Modules\Sacraments\Tests\Unit;

use Modules\Sacraments\Definitions\SacramentDefinitionRegistry;
use Modules\Sacraments\Services\SacramentDuplicateDetector;
use Modules\Sacraments\Support\SacramentEventSubtype;
use Modules\Sacraments\Support\SacramentOrdinationType;
use Modules\Sacraments\Support\SacramentPrivacyClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SacramentDuplicateDetectorTest extends TestCase
{
    #[Test]
    public function definition_registry_marks_reconciliation_restricted_and_ungated(): void
    {
        $def = (new SacramentDefinitionRegistry)->forTypeCode('RECONCILIATION');
        $this->assertNotNull($def);
        $this->assertSame(SacramentPrivacyClass::RESTRICTED, $def['privacy_class']);
        $this->assertFalse($def['gated']);
        $this->assertTrue($def['certificate_supported']);
    }

    #[Test]
    public function eucharist_defaults_first_communion_subtype(): void
    {
        $def = (new SacramentDefinitionRegistry)->forTypeCode('EUCHARIST');
        $this->assertSame(SacramentEventSubtype::FIRST_COMMUNION, $def['default_event_subtype']);
        $this->assertSame('warn_same_person_subtype', $def['repeatability_policy']);
    }

    #[Test]
    public function holy_orders_lists_ordination_types(): void
    {
        $def = (new SacramentDefinitionRegistry)->forTypeCode('HOLY_ORDERS');
        $this->assertSame(SacramentOrdinationType::all(), $def['ordination_types']);
        $this->assertSame('warn_same_candidate_ordination', $def['repeatability_policy']);
    }

    #[Test]
    public function duplicate_detector_is_constructable(): void
    {
        $detector = new SacramentDuplicateDetector(new SacramentDefinitionRegistry);
        $this->assertInstanceOf(SacramentDuplicateDetector::class, $detector);
    }
}
