<?php

namespace Modules\Family\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Family\app\Services\FamilyAuditService;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\ActiveSupportSession;
use Modules\Tenants\Support\SupportSessionMode;
use Modules\Tenants\Support\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FamilyAuditSupportSessionStampTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function log_stamps_support_session_id_when_context_elevated(): void
    {
        $tenant = Tenant::factory()->create(['active' => 1]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user, 'api');

        $session = new ActiveSupportSession(
            id: '33333333-3333-3333-3333-333333333333',
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

        $log = app(FamilyAuditService::class)->log(
            (int) $tenant->id,
            'family.created',
            'family',
            'family-1',
        );

        $this->assertSame('33333333-3333-3333-3333-333333333333', $log->support_session_id);
        $this->assertSame((int) $user->id, (int) $log->actor_user_id);
    }
}
