<?php

namespace Modules\SupportTickets\Support;

use Modules\SupportAccess\Contracts\ExternalHelpdeskTicketAdapter;
use Modules\SupportTickets\Models\SupportTicket;
use Modules\SupportTickets\Support\TicketStatus;
use RuntimeException;

class NativeSupportTicketAdapter implements ExternalHelpdeskTicketAdapter
{
    public function assertTicketExists(string $ticketRef): void
    {
        $ref = strtoupper(trim($ticketRef));

        if (! preg_match('/^ES-\d{6}$/', $ref)) {
            throw new RuntimeException('Ticket reference format is invalid.');
        }

        $ticket = SupportTicket::query()->byTicketNumber($ref)->first();

        if (! $ticket) {
            throw new RuntimeException('Support ticket not found.');
        }

        if (in_array($ticket->status, [TicketStatus::CANCELLED, TicketStatus::CLOSED], true)) {
            throw new RuntimeException('Support ticket is closed and cannot be linked to a session.');
        }
    }
}
