<?php

namespace Modules\Donations\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Donations\Services\DonationAuditService;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\ActiveSupportSession;
use Modules\Tenants\Support\SupportSessionMode;
use Modules\Tenants\Support\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DonationAuditSupportSessionStampTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function log_stamps_support_session_id_when_context_elevated(): void
    {
        $tenant = Tenant::factory()->create(['active' => 1]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user, 'api');

        $session = new ActiveSupportSession(
            id: '22222222-2222-2222-2222-222222222222',
            tenantId: (int) $tenant->id,
            mode: SupportSessionMode::Readonly,
            supportUserId: (int) $user->id,
            expiresAt: new \DateTimeImmutable('+30 minutes'),
            reasonCode: 'diagnosis',
        );

        $this->app->instance(
            TenantContext::class,
            TenantContext::fromUserAndSession($user, $session)
        );

        $log = app(DonationAuditService::class)->log(
            (int) $tenant->id,
            'test.event',
            'donation',
            '1',
        );

        $this->assertSame('22222222-2222-2222-2222-222222222222', $log->support_session_id);
    }
}
