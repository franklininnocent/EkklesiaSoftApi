<?php

namespace Modules\Tenants\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tenants\Jobs\TenantAwareJob;
use Modules\Tenants\Support\TenantContext;
use Modules\Tenants\Support\TenantContextBinder;
use Tests\TestCase;

class TenantAwareJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_binds_and_clears_tenant_context(): void
    {
        $job = new class(55, 99) extends TenantAwareJob
        {
            public bool $ran = false;

            public ?int $capturedTenantId = null;

            public ?int $capturedActorId = null;

            protected function handleWithTenantContext(): void
            {
                $this->ran = true;
                $context = app(TenantContext::class);
                $this->capturedTenantId = $context->effectiveTenantId();
                $this->capturedActorId = $context->actorUserId();
            }
        };

        $job->handle();

        $this->assertTrue($job->ran);
        $this->assertSame(55, $job->capturedTenantId);
        $this->assertSame(99, $job->capturedActorId);
        $this->assertNull(app(TenantContext::class)->effectiveTenantId());
    }
}
