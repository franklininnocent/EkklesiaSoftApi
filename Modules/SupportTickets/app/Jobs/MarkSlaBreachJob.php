<?php

namespace Modules\SupportTickets\Jobs;

use Modules\SupportTickets\Models\SupportTicket;
use Modules\SupportTickets\Support\TicketStatus;
use Modules\Tenants\Jobs\TenantAwareJob;

class MarkSlaBreachJob extends TenantAwareJob
{
    public function __construct(
        int $tenantId,
        private readonly int $ticketId,
    ) {
        parent::__construct($tenantId);
    }

    protected function handleWithTenantContext(): void
    {
        $ticket = SupportTicket::query()->whereKey($this->ticketId)->first();
        if (! $ticket || in_array($ticket->status, [TicketStatus::RESOLVED, TicketStatus::CLOSED, TicketStatus::CANCELLED], true)) {
            return;
        }

        $now = now();
        if ($ticket->first_response_at === null && $ticket->first_response_due_at && $ticket->first_response_due_at->lte($now)) {
            $ticket->first_response_breached = true;
        }
        if ($ticket->resolved_at === null && $ticket->resolution_due_at && $ticket->resolution_due_at->lte($now)) {
            $ticket->resolution_breached = true;
        }
        $ticket->save();
    }
}
