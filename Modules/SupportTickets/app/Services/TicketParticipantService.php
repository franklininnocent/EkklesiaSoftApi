<?php

namespace Modules\SupportTickets\Services;

use Modules\Authentication\Models\User;
use Modules\SupportTickets\Exceptions\SupportTicketException;
use Modules\SupportTickets\Models\SupportTicket;
use Modules\SupportTickets\Models\SupportTicketParticipant;
use Modules\SupportTickets\Support\TicketEventType;

class TicketParticipantService
{
    public function __construct(
        private readonly TicketEventRecorder $events,
    ) {}

    public function add(SupportTicket $ticket, User $actor, int $userId): SupportTicketParticipant
    {
        $user = User::query()
            ->whereKey($userId)
            ->where('tenant_id', $ticket->tenant_id)
            ->where('active', 1)
            ->first();

        if (! $user) {
            throw new SupportTicketException('User not found in this parish.', 404);
        }

        if (! $user->hasPermission('support.tickets.view')) {
            throw new SupportTicketException('User does not have permission to view support tickets.', 422);
        }

        if ($ticket->requester_user_id === $userId) {
            throw new SupportTicketException('Requester is already on this ticket.', 422);
        }

        $participant = SupportTicketParticipant::query()->firstOrCreate(
            ['ticket_id' => $ticket->id, 'user_id' => $userId],
            [
                'tenant_id' => $ticket->tenant_id,
                'added_by_user_id' => $actor->id,
            ]
        );

        if ($participant->wasRecentlyCreated) {
            $this->events->record(
                $ticket,
                (int) $actor->id,
                TicketEventType::PARTICIPANT_ADDED,
                null,
                (string) $userId
            );
        }

        return $participant->load('user:id,name');
    }

    public function remove(SupportTicket $ticket, User $actor, int $userId): void
    {
        $deleted = SupportTicketParticipant::query()
            ->where('ticket_id', $ticket->id)
            ->where('user_id', $userId)
            ->delete();

        if ($deleted) {
            $this->events->record(
                $ticket,
                (int) $actor->id,
                TicketEventType::PARTICIPANT_REMOVED,
                (string) $userId,
                null
            );
        }
    }
}
