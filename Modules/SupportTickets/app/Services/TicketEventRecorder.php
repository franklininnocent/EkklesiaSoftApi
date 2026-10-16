<?php

namespace Modules\SupportTickets\Services;

use Modules\SupportTickets\Models\SupportTicket;
use Modules\SupportTickets\Models\SupportTicketEvent;
use Modules\Tenants\Support\TenantContext;

class TicketEventRecorder
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        SupportTicket $ticket,
        int $actorUserId,
        string $eventType,
        ?string $oldValue = null,
        ?string $newValue = null,
        ?string $reason = null,
        ?array $metadata = null,
    ): SupportTicketEvent {
        $context = app(TenantContext::class);

        return SupportTicketEvent::query()->create([
            'ticket_id' => $ticket->id,
            'tenant_id' => $ticket->tenant_id,
            'actor_user_id' => $actorUserId,
            'support_session_id' => $context->supportSessionId(),
            'event_type' => $eventType,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'reason' => $reason,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
