<?php

namespace Modules\SupportAccess\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\SupportAccess\Models\SupportSession;
use Modules\SupportAccess\Support\DatabaseSupportSessionResolver;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DatabaseSupportSessionResolverTimezoneTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function freshly_created_session_survives_resolver_reload(): void
    {
        $user = User::factory()->create(['tenant_id' => null]);
        $tenant = Tenant::factory()->active()->create();

        $sessionId = (string) Str::uuid();
        SupportSession::query()->create([
            'id' => $sessionId,
            'support_user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'mode' => 'standard',
            'reason_code' => 'diagnosis',
            'status' => SupportSession::STATUS_ACTIVE,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        $reloaded = SupportSession::query()->findOrFail($sessionId);
        $this->assertTrue($reloaded->expires_at?->isFuture());
        $this->assertTrue($reloaded->isActive());

        $request = Request::create('/api/families/statistics', 'GET');
        $request->headers->set(DatabaseSupportSessionResolver::HEADER, $sessionId);

        $active = app(DatabaseSupportSessionResolver::class)->resolve($request, $user);

        $this->assertSame($sessionId, $active->id());
        $this->assertSame(SupportSession::STATUS_ACTIVE, SupportSession::query()->find($sessionId)?->status);
    }
}
