<?php

namespace Modules\SupportTickets\Services;

use Carbon\Carbon;
use Modules\SupportTickets\Models\SupportSlaPolicy;
use Modules\SupportTickets\Models\SupportTicket;

class SlaCalculator
{
    public function applyDueDates(SupportTicket $ticket): void
    {
        $policy = SupportSlaPolicy::query()
            ->where('priority', $ticket->priority)
            ->first();

        if (! $policy) {
            return;
        }

        $now = Carbon::now();
        $ticket->first_response_due_at = $now->copy()->addMinutes($policy->first_response_minutes);
        $ticket->resolution_due_at = $now->copy()->addMinutes($policy->resolution_minutes);
    }

    public function pause(SupportTicket $ticket): void
    {
        if ($ticket->paused_at !== null) {
            return;
        }

        $ticket->paused_at = now();
    }

    public function resume(SupportTicket $ticket): void
    {
        if ($ticket->paused_at === null) {
            return;
        }

        $pausedSeconds = (int) $ticket->paused_at->diffInSeconds(now());
        $ticket->total_paused_seconds = (int) $ticket->total_paused_seconds + $pausedSeconds;
        $ticket->paused_at = null;

        if ($ticket->first_response_due_at) {
            $ticket->first_response_due_at = $ticket->first_response_due_at->copy()->addSeconds($pausedSeconds);
        }
        if ($ticket->resolution_due_at) {
            $ticket->resolution_due_at = $ticket->resolution_due_at->copy()->addSeconds($pausedSeconds);
        }
    }
}
