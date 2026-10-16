<?php

namespace Modules\Subscriptions\Tests\Unit;

use Modules\Subscriptions\Support\SubscriptionFeatureCapabilityCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionFeatureCapabilityCatalogTest extends TestCase
{
    #[Test]
    public function implemented_entitlement_codes_include_verified_launch_capabilities(): void
    {
        $codes = SubscriptionFeatureCapabilityCatalog::implementedEntitlementCodes();

        $this->assertContains('MASS_INTENTIONS', $codes);
        $this->assertContains('IMPORT_EXPORT', $codes);
        $this->assertContains('BASIC_REPORTS', $codes);
        $this->assertContains('CONTRIBUTION_PLANS', $codes);
        $this->assertContains('AUDIT_LOG', $codes);
    }

    #[Test]
    public function data_capability_copy_does_not_advertise_import(): void
    {
        $description = \Modules\Subscriptions\Support\SubscriptionDataCapabilityCatalog::featureDescriptions()['IMPORT_EXPORT'];
        $this->assertStringContainsString('Export', $description);
        $this->assertStringContainsString('Import is not offered', $description);
    }
}
