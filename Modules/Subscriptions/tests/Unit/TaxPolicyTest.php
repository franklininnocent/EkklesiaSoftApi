<?php

namespace Modules\Subscriptions\Tests\Unit;

use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Support\SubscriptionCatalogDefinition;
use Modules\Subscriptions\Support\TaxPolicy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TaxPolicyTest extends TestCase
{
    #[Test]
    public function inherits_platform_when_version_rate_is_null(): void
    {
        $platform = SubscriptionCatalogDefinition::defaultPolicies()['tax'];
        $version = new PlanVersion([
            'tax_rate_percent' => null,
            'tax_label' => null,
            'tax_inclusive' => true,
        ]);

        $resolved = TaxPolicy::resolve($version, $platform);

        $this->assertSame('platform', $resolved['source']);
        $this->assertSame('18.00', $resolved['rate_percent']);
        $this->assertFalse($resolved['prices_include_tax']);
        $this->assertSame('GST', $resolved['label']);
    }

    #[Test]
    public function uses_version_override_when_rate_is_set(): void
    {
        $platform = SubscriptionCatalogDefinition::defaultPolicies()['tax'];
        $version = new PlanVersion([
            'tax_rate_percent' => '12.00',
            'tax_label' => 'GST',
            'tax_inclusive' => true,
        ]);

        $resolved = TaxPolicy::resolve($version, $platform);

        $this->assertSame('plan_version', $resolved['source']);
        $this->assertSame('12.00', $resolved['rate_percent']);
        $this->assertTrue($resolved['prices_include_tax']);
    }

    #[Test]
    public function zero_platform_rate_is_valid(): void
    {
        $platform = array_replace(SubscriptionCatalogDefinition::defaultPolicies()['tax'], ['rate_percent' => '0.00']);
        $resolved = TaxPolicy::resolve(null, $platform);

        $this->assertSame('0.00', $resolved['rate_percent']);
        $this->assertSame('platform', $resolved['source']);
    }
}
