<?php

namespace Modules\ApplicationAccess\Tests\Unit;

use Illuminate\Http\Request;
use Modules\ApplicationAccess\Support\ApplicationAccessCaptureExemptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationAccessCaptureExemptionsTest extends TestCase
{
    private ApplicationAccessCaptureExemptions $exemptions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exemptions = new ApplicationAccessCaptureExemptions;
    }

    #[Test]
    public function it_exempts_health_and_self_admin_paths(): void
    {
        $this->assertTrue($this->exemptions->isExempt(Request::create('/up', 'GET')));
        $this->assertTrue($this->exemptions->isExempt(Request::create('/api/admin/application-access/health', 'GET')));
        $this->assertTrue($this->exemptions->isExempt(Request::create('/api/admin/application-access/stream', 'GET')));
    }

    #[Test]
    public function it_exempts_media_and_options_requests(): void
    {
        $this->assertTrue($this->exemptions->isExempt(Request::create('/api/tenant/media/serve', 'GET')));
        $this->assertTrue($this->exemptions->isExempt(Request::create('/api/families', 'OPTIONS')));
    }

    #[Test]
    public function it_does_not_exempt_regular_tenant_api_paths(): void
    {
        $this->assertFalse($this->exemptions->isExempt(Request::create('/api/families', 'GET')));
        $this->assertFalse($this->exemptions->isExempt(Request::create('/api/auth/login', 'POST')));
    }
}
