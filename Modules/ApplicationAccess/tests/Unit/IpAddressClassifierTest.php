<?php

namespace Modules\ApplicationAccess\Tests\Unit;

use Modules\ApplicationAccess\Support\IpAddressClassifier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IpAddressClassifierTest extends TestCase
{
    private IpAddressClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new IpAddressClassifier;
    }

    #[Test]
    public function it_classifies_public_ipv4(): void
    {
        $result = $this->classifier->classify('203.0.113.5');

        $this->assertSame('203.0.113.5', $result['ip_address']);
        $this->assertSame(4, $result['ip_version']);
        $this->assertSame(IpAddressClassifier::CLASS_PUBLIC, $result['ip_class']);
    }

    #[Test]
    public function it_classifies_private_and_loopback(): void
    {
        $this->assertSame(
            IpAddressClassifier::CLASS_LOOPBACK,
            $this->classifier->classify('127.0.0.1')['ip_class']
        );
        $this->assertSame(
            IpAddressClassifier::CLASS_PRIVATE,
            $this->classifier->classify('10.0.0.5')['ip_class']
        );
        $this->assertSame(
            IpAddressClassifier::CLASS_PRIVATE,
            $this->classifier->classify('192.168.1.10')['ip_class']
        );
    }

    #[Test]
    public function it_normalizes_ipv4_mapped_ipv6(): void
    {
        $result = $this->classifier->classify('::ffff:203.0.113.5');

        $this->assertSame('203.0.113.5', $result['ip_address']);
        $this->assertSame(4, $result['ip_version']);
        $this->assertSame(IpAddressClassifier::CLASS_PUBLIC, $result['ip_class']);
    }

    #[Test]
    public function it_classifies_link_local_as_internal(): void
    {
        $this->assertSame(
            IpAddressClassifier::CLASS_INTERNAL,
            $this->classifier->classify('169.254.10.1')['ip_class']
        );
    }

    #[Test]
    public function untrusted_forwarded_ip_is_not_used_by_classifier(): void
    {
        $request = \Illuminate\Http\Request::create(
            '/api/admin/application-access/health',
            'GET',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.5',
            ]
        );

        $result = $this->classifier->classify($request->ip());

        $this->assertSame('10.0.0.1', $result['ip_address']);
    }
}
