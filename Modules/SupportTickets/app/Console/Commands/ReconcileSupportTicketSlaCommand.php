<?php

namespace Modules\SupportTickets\Console\Commands;

use Illuminate\Console\Command;
use Modules\SupportTickets\Jobs\MarkSlaBreachJob;
use Modules\SupportTickets\Models\SupportTicket;
use Modules\SupportTickets\Support\TicketStatus;

class ReconcileSupportTicketSlaCommand extends Command
{
    protected $signature = 'support-tickets:reconcile-sla';

    protected $description = 'Reconcile SLA breach flags for open support tickets';

    public function handle(): int
    {
        $now = now();

        SupportTicket::query()
            ->whereNotIn('status', [TicketStatus::RESOLVED, TicketStatus::CLOSED, TicketStatus::CANCELLED])
            ->where(function ($q) use ($now): void {
                $q->where(function ($inner) use ($now): void {
                    $inner->whereNull('first_response_at')
                        ->where('first_response_due_at', '<=', $now);
                })->orWhere(function ($inner) use ($now): void {
                    $inner->whereNull('resolved_at')
                        ->where('resolution_due_at', '<=', $now);
                });
            })
            ->select(['id', 'tenant_id'])
            ->chunkById(100, function ($tickets): void {
                foreach ($tickets as $ticket) {
                    MarkSlaBreachJob::dispatch((int) $ticket->tenant_id, (int) $ticket->id);
                }
            });

        $this->info('SLA reconciliation dispatched.');

        return self::SUCCESS;
    }
}
