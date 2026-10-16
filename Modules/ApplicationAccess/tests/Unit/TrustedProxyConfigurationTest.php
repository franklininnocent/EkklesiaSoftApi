<?php

namespace Modules\ApplicationAccess\Tests\Unit;

use Modules\ApplicationAccess\Support\TrustedProxyConfiguration;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TrustedProxyConfigurationTest extends TestCase
{
    #[Test]
    public function it_returns_empty_when_env_unset(): void
    {
        putenv('TRUSTED_PROXIES');

        $this->assertSame([], TrustedProxyConfiguration::proxies());
    }

    #[Test]
    public function it_parses_comma_separated_cidrs(): void
    {
        putenv('TRUSTED_PROXIES=10.0.0.0/8, 192.168.1.1');

        try {
            $this->assertSame(['10.0.0.0/8', '192.168.1.1'], TrustedProxyConfiguration::proxies());
        } finally {
            putenv('TRUSTED_PROXIES');
        }
    }

    #[Test]
    public function it_never_returns_wildcard(): void
    {
        putenv('TRUSTED_PROXIES=*');

        try {
            $this->assertSame([], TrustedProxyConfiguration::proxies());
        } finally {
            putenv('TRUSTED_PROXIES');
        }
    }
}
