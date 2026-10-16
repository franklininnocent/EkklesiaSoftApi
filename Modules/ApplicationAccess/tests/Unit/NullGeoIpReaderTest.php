<?php

namespace Modules\ApplicationAccess\Tests\Unit;

use Modules\ApplicationAccess\Support\IpAddressClassifier;
use Modules\ApplicationAccess\Support\NullGeoIpReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NullGeoIpReaderTest extends TestCase
{
    #[Test]
    public function it_returns_private_network_for_private_ips(): void
    {
        $reader = new NullGeoIpReader;

        $result = $reader->lookup('10.0.0.1', IpAddressClassifier::CLASS_PRIVATE);

        $this->assertSame('PRIVATE_NETWORK', $result['geo_status']);
        $this->assertNull($result['country']);
    }

    #[Test]
    public function it_returns_lookup_failed_for_public_ips(): void
    {
        $reader = new NullGeoIpReader;

        $result = $reader->lookup('203.0.113.5', IpAddressClassifier::CLASS_PUBLIC);

        $this->assertSame('LOOKUP_FAILED', $result['geo_status']);
    }
}
