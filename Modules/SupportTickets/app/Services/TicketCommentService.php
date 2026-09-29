<?php

namespace Modules\SupportTickets\Services;

use Modules\Authentication\Models\User;
use Modules\SupportTickets\Exceptions\SupportTicketException;
use Modules\SupportTickets\Models\SupportTicket;
use Modules\SupportTickets\Models\SupportTicketComment;
use Modules\SupportTickets\Support\TicketEventType;
use Modules\SupportTickets\Support\TicketStatus;

class TicketCommentService
{
    public function __construct(
        private readonly TicketEventRecorder $events,
        private readonly SlaCalculator $sla,
        private readonly SupportTicketService $tickets,
    ) {}

    public function addPublicComment(SupportTicket $ticket, User $actor, string $body): SupportTicketComment
    {
        $body = trim(strip_tags($body));
        if ($body === '') {
            throw new SupportTicketException('Comment cannot be empty.', 422);
        }

        $isOps = $this->tickets->actorHasOpsView($actor);

        $comment = SupportTicketComment::query()->create([
            'ticket_id' => $ticket->id,
            'tenant_id' => $ticket->tenant_id,
            'author_user_id' => $actor->id,
            'is_internal' => false,
            'body' => $body,
        ]);

        if ($isOps) {
            if ($ticket->status === TicketStatus::NEW) {
                $ticket->status = TicketStatus::IN_PROGRESS;
            }
            if ($ticket->first_response_at === null) {
                $ticket->first_response_at = now();
            }
            $ticket->save();
        } else {
            if ($ticket->status === TicketStatus::AWAITING_YOU) {
                $this->sla->resume($ticket);
                $ticket->status = TicketStatus::IN_PROGRESS;
                $ticket->save();
            }
        }

        $this->events->record($ticket, (int) $actor->id, TicketEventType::PUBLIC_COMMENT);

        return $comment->load('author:id,name');
    }

    public function addInternalNote(SupportTicket $ticket, User $actor, string $body): SupportTicketComment
    {
        $body = trim(strip_tags($body));
        if ($body === '') {
            throw new SupportTicketException('Internal note cannot be empty.', 422);
        }

        $comment = SupportTicketComment::query()->create([
            'ticket_id' => $ticket->id,
            'tenant_id' => $ticket->tenant_id,
            'author_user_id' => $actor->id,
            'is_internal' => true,
            'body' => $body,
        ]);

        $this->events->record($ticket, (int) $actor->id, TicketEventType::INTERNAL_NOTE);

        return $comment->load('author:id,name');
    }
}
