<?php

namespace Modules\SupportAccess\Tests\Unit;

use Illuminate\Http\Request;
use Modules\SupportAccess\Http\Middleware\EnforceSupportSessionMode;
use Modules\Tenants\Support\ActiveSupportSession;
use Modules\Tenants\Support\SupportSessionMode;
use Modules\Tenants\Support\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnforceSupportSessionModeTest extends TestCase
{
    private EnforceSupportSessionMode $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new EnforceSupportSessionMode;
    }

    #[Test]
    public function without_support_session_mutating_requests_pass(): void
    {
        $this->app->instance(TenantContext::class, TenantContext::empty());

        $response = $this->middleware->handle(
            Request::create('/api/families', 'POST'),
            static fn () => response('ok', 200)
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function readonly_blocks_post_to_tenant_apis(): void
    {
        $this->bindSupportContext(SupportSessionMode::Readonly);

        $response = $this->middleware->handle(
            Request::create('/api/families', 'POST'),
            static fn () => response('ok', 200)
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('read-only', (string) $response->getContent());
    }

    #[Test]
    public function readonly_allows_get(): void
    {
        $this->bindSupportContext(SupportSessionMode::Readonly);

        $response = $this->middleware->handle(
            Request::create('/api/families', 'GET'),
            static fn () => response('ok', 200)
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function readonly_allows_support_center_mutations(): void
    {
        $this->bindSupportContext(SupportSessionMode::Readonly);

        $response = $this->middleware->handle(
            Request::create('/api/support/sessions/abc/end', 'POST'),
            static fn () => response('ok', 200)
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function standard_blocks_billing_paths(): void
    {
        $this->bindSupportContext(SupportSessionMode::Standard);

        $response = $this->middleware->handle(
            Request::create('/api/billing/invoices', 'POST'),
            static fn () => response('ok', 200)
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('blocked', (string) $response->getContent());
    }

    #[Test]
    public function emergency_blocks_billing_paths(): void
    {
        $this->bindSupportContext(SupportSessionMode::Emergency);

        $response = $this->middleware->handle(
            Request::create('/api/subscriptions/change', 'PUT'),
            static fn () => response('ok', 200)
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function standard_allows_non_denied_mutations(): void
    {
        $this->bindSupportContext(SupportSessionMode::Standard);

        $response = $this->middleware->handle(
            Request::create('/api/families', 'POST'),
            static fn () => response('ok', 200)
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    private function bindSupportContext(SupportSessionMode $mode): void
    {
        $session = new ActiveSupportSession(
            id: 'sess-test-1',
            tenantId: 42,
            mode: $mode,
            supportUserId: 7,
            expiresAt: new \DateTimeImmutable('+30 minutes'),
            reasonCode: 'diagnosis',
        );

        $user = new class implements \Illuminate\Contracts\Auth\Authenticatable
        {
            public ?int $tenant_id = null;

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier(): mixed
            {
                return 7;
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
        };

        $this->app->instance(
            TenantContext::class,
            TenantContext::fromUserAndSession($user, $session)
        );
    }
}
