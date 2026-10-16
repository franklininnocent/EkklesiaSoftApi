<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Tenants\Services\PlatformAuditLogger;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformAuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_persists_redacted_platform_audit_events(): void
    {
        app(PlatformAuditLogger::class)->record(
            category: 'security',
            event: 'auth_failure',
            tenantId: 12,
            actorUserId: null,
            httpStatus: 401,
            requestMethod: 'GET',
            requestPath: '/api/families',
            metadata: [
                'user_email' => 'probe@example.com',
                'password' => 'secret',
            ],
        );

        $row = DB::table('platform_audit_logs')->first();
        $this->assertNotNull($row);
        $this->assertSame('security', $row->category);
        $this->assertSame('auth_failure', $row->event);
        $metadata = json_decode((string) $row->metadata, true);
        $this->assertSame('[redacted]', $metadata['password']);
        $this->assertSame('p***@example.com', $metadata['user_email']);
    }
}
