<?php

namespace Modules\Tenants\Tests\Unit;

use Modules\Tenants\Support\TenantFacingAuditActor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TenantFacingAuditActorTest extends TestCase
{
    #[Test]
    public function real_name_with_null_session_returns_real_name(): void
    {
        $this->assertSame(
            'Parish Secretary',
            TenantFacingAuditActor::displayName('Parish Secretary', null)
        );
    }

    #[Test]
    public function real_name_with_empty_session_returns_real_name(): void
    {
        $this->assertSame(
            'Parish Secretary',
            TenantFacingAuditActor::displayName('Parish Secretary', '')
        );
        $this->assertSame(
            'Parish Secretary',
            TenantFacingAuditActor::displayName('Parish Secretary', '   ')
        );
    }

    #[Test]
    public function real_name_with_session_id_returns_ekklesia_support(): void
    {
        $this->assertSame(
            TenantFacingAuditActor::DISPLAY_NAME,
            TenantFacingAuditActor::displayName(
                'Franklin Innocent F',
                '33333333-3333-3333-3333-333333333333'
            )
        );
    }

    #[Test]
    public function null_name_with_session_id_returns_ekklesia_support(): void
    {
        $this->assertSame(
            TenantFacingAuditActor::DISPLAY_NAME,
            TenantFacingAuditActor::displayName(null, '33333333-3333-3333-3333-333333333333')
        );
    }

    #[Test]
    public function null_name_with_null_session_returns_null(): void
    {
        $this->assertNull(TenantFacingAuditActor::displayName(null, null));
    }
}
