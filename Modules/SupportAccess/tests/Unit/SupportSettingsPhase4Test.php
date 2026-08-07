<?php

namespace Modules\SupportAccess\Tests\Unit;

use Modules\SupportAccess\Services\SupportSettingsService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupportSettingsPhase4Test extends TestCase
{
    #[Test]
    public function defaults_include_phase4_controls(): void
    {
        $defaults = app(SupportSettingsService::class)->defaults();

        $this->assertTrue($defaults['emergency_requires_approval']);
        $this->assertFalse($defaults['require_customer_grant']);
        $this->assertTrue($defaults['jit_enabled']);
        $this->assertSame(15, $defaults['jit_timeout_minutes']);
        $this->assertSame(60, $defaults['approval_request_ttl_minutes']);
    }
}
