<?php

namespace Modules\Tenants\Tests\Unit;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Tenants\Support\ActiveSupportSession;
use Modules\Tenants\Support\SupportSessionMode;
use Modules\Tenants\Support\TenantContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantContextTest extends TestCase
{
    public function test_empty_context_has_no_effective_tenant(): void
    {
        $context = TenantContext::empty();

        $this->assertNull($context->effectiveTenantId());
        $this->assertFalse($context->isSupportSession());
    }

    public function test_require_effective_tenant_id_rejects_null(): void
    {
        $this->expectException(HttpException::class);

        TenantContext::empty()->requireEffectiveTenantId();
    }

    public function test_support_session_overrides_home_tenant(): void
    {
        $user = new FakeAuthUser(99, 10);

        $session = new ActiveSupportSession(
            id: 'sess-1',
            tenantId: 42,
            mode: SupportSessionMode::Readonly,
            supportUserId: 99,
            expiresAt: new \DateTimeImmutable('+30 minutes'),
            reasonCode: 'diagnosis',
        );

        $context = TenantContext::fromUserAndSession($user, $session);

        $this->assertSame(99, $context->actorUserId());
        $this->assertSame(10, $context->homeTenantId());
        $this->assertSame(42, $context->effectiveTenantId());
        $this->assertTrue($context->isSupportSession());
        $this->assertSame('sess-1', $context->supportSessionId());
        $this->assertSame(SupportSessionMode::Readonly, $context->supportMode());
    }

    public function test_without_session_effective_equals_home(): void
    {
        $user = new FakeAuthUser(3, 7);

        $context = TenantContext::fromUserAndSession($user, null);

        $this->assertSame(7, $context->effectiveTenantId());
        $this->assertSame(7, $context->requireEffectiveTenantId());
        $this->assertFalse($context->isSupportSession());
    }
}

final class FakeAuthUser implements Authenticatable
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
