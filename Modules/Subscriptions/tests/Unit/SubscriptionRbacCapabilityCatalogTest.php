<?php

namespace Modules\Subscriptions\Tests\Unit;

use Modules\Subscriptions\Support\SubscriptionRbacCapabilityCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionRbacCapabilityCatalogTest extends TestCase
{
    #[Test]
    public function catalog_lists_both_entitlements_with_customer_notes(): void
    {
        $rows = SubscriptionRbacCapabilityCatalog::rows();
        $this->assertNotEmpty($rows);
        $entitlements = array_column($rows, 'entitlement');
        $this->assertContains('RBAC_BASIC', $entitlements);
        $this->assertContains('RBAC_ADVANCED', $entitlements);
        foreach ($rows as $row) {
            $this->assertNotEmpty($row['label']);
            $this->assertNotEmpty($row['customer_note']);
        }
    }

    #[Test]
    public function feature_descriptions_match_catalog_codes(): void
    {
        $descriptions = SubscriptionRbacCapabilityCatalog::featureDescriptions();
        $this->assertArrayHasKey('RBAC_BASIC', $descriptions);
        $this->assertArrayHasKey('RBAC_ADVANCED', $descriptions);
        $this->assertStringContainsString('custom', strtolower($descriptions['RBAC_ADVANCED']));
    }
}
