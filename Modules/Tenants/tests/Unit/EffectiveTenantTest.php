<?php

namespace Modules\Tenants\Tests\Unit;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Tenants\Support\ActiveSupportSession;
use Modules\Tenants\Support\EffectiveTenant;
use Modules\Tenants\Support\SupportSessionMode;
use Modules\Tenants\Support\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EffectiveTenantTest extends TestCase
{
    #[Test]
    public function matches_uses_support_effective_tenant_not_home(): void
    {
        $user = new EffectiveTenantFakeUser(99, null);

        $session = new ActiveSupportSession(
            id: 'sess-1',
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

        $this->assertSame(42, EffectiveTenant::id($user));
        $this->assertTrue(EffectiveTenant::matches($user, 42));
        $this->assertFalse(EffectiveTenant::matches($user, 7));
    }

    #[Test]
    public function falls_back_to_home_tenant_when_no_session(): void
    {
        $user = new EffectiveTenantFakeUser(3, 7);

        $this->app->instance(
            TenantContext::class,
            TenantContext::fromUserAndSession($user, null)
        );

        $this->assertSame(7, EffectiveTenant::id($user));
        $this->assertTrue(EffectiveTenant::matches($user, 7));
    }
}

final class EffectiveTenantFakeUser implements Authenticatable
{
    public function __construct(
        private readonly int $id,
        public ?int $tenant_id,
    ) {
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
