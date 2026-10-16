<?php

namespace Modules\SupportTickets\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SupportTickets\Jobs\MarkSlaBreachJob;
use Modules\SupportTickets\Jobs\MarkSlaWarningJob;
use Modules\SupportTickets\Models\SupportTicket;
use Modules\SupportTickets\Support\TicketStatus;
use Modules\Tenants\Models\Tenant;
use Tests\TestCase;

class MarkSlaJobsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sla_warning_job_does_not_update_ticket_in_another_tenant(): void
    {
        $tenantA = Tenant::factory()->active()->create();
        $tenantB = Tenant::factory()->active()->create();

        $ticketB = SupportTicket::factory()->create([
            'tenant_id' => $tenantB->id,
            'status' => TicketStatus::NEW,
            'sla_warning_sent' => false,
        ]);

        (new MarkSlaWarningJob($tenantA->id, $ticketB->id))->handle();

        $ticketB->refresh();
        $this->assertFalse($ticketB->sla_warning_sent);
    }

    public function test_sla_breach_job_does_not_update_ticket_in_another_tenant(): void
    {
        $tenantA = Tenant::factory()->active()->create();
        $tenantB = Tenant::factory()->active()->create();

        $ticketB = SupportTicket::factory()->create([
            'tenant_id' => $tenantB->id,
            'status' => TicketStatus::NEW,
            'first_response_due_at' => now()->subHour(),
            'first_response_breached' => false,
        ]);

        (new MarkSlaBreachJob($tenantA->id, $ticketB->id))->handle();

        $ticketB->refresh();
        $this->assertFalse($ticketB->first_response_breached);
    }
}
