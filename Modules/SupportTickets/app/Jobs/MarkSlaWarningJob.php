<?php

namespace Modules\SupportTickets\Jobs;

use Modules\SupportTickets\Models\SupportTicket;
use Modules\SupportTickets\Support\TicketStatus;
use Modules\Tenants\Jobs\TenantAwareJob;

class MarkSlaWarningJob extends TenantAwareJob
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

        if (! $ticket->sla_warning_sent) {
            $ticket->sla_warning_sent = true;
            $ticket->save();
        }
    }
}
