<?php

namespace Modules\MinistriesAssociations\Tests\Unit;

use Modules\Authentication\Models\User;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Policies\OrganizationPolicy;
use Modules\Tenants\Support\ActiveSupportSession;
use Modules\Tenants\Support\SupportSessionMode;
use Modules\Tenants\Support\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizationPolicyEffectiveTenantTest extends TestCase
{
    #[Test]
    public function support_actor_with_null_home_tenant_can_view_when_session_matches(): void
    {
        $user = new User();
        $user->id = 99;
        $user->tenant_id = null;
        $user->setRelation('roles', collect());

        $org = new Organization();
        $org->tenant_id = 42;

        $session = new ActiveSupportSession(
            id: 'sess-pol-1',
            tenantId: 42,
            mode: SupportSessionMode::Standard,
            supportUserId: 99,
            expiresAt: new \DateTimeImmutable('+30 minutes'),
            reasonCode: 'diagnosis',
        );

        $this->app->instance(
            TenantContext::class,
            TenantContext::fromUserAndSession($user, $session)
        );

        $policy = new OrganizationPolicy();

        // Bypass permission via SuperAdmin stub.
        $user = new class extends User {
            public function isSuperAdmin(): bool
            {
                return true;
            }
        };
        $user->id = 99;
        $user->tenant_id = null;

        $this->app->instance(
            TenantContext::class,
            TenantContext::fromUserAndSession($user, $session)
        );

        $this->assertTrue($policy->view($user, $org));

        $other = new Organization();
        $other->tenant_id = 7;
        $this->assertFalse($policy->view($user, $other));
    }
}
