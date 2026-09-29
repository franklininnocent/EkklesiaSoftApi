<?php

namespace Modules\SupportAccess\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SupportAccess\Services\SupportTicketValidationService;
use Modules\SupportTickets\Models\SupportTicket;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SupportTicketTenantMatchTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function native_ticket_ref_must_match_requested_tenant(): void
    {
        $tenantA = Tenant::factory()->active()->create();
        $tenantB = Tenant::factory()->active()->create();

        SupportTicket::factory()->create([
            'tenant_id' => $tenantA->id,
            'ticket_number' => 'ES-000123',
        ]);

        $service = app(SupportTicketValidationService::class);

        $service->assertTenantMatches('ES-000123', (int) $tenantA->id);
        $this->addToAssertionCount(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not belong to the selected tenant');
        $service->assertTenantMatches('ES-000123', (int) $tenantB->id);
    }

    #[Test]
    public function empty_ticket_ref_skips_tenant_match(): void
    {
        $service = app(SupportTicketValidationService::class);
        $service->assertTenantMatches(null, 99);
        $this->addToAssertionCount(1);
    }
}
