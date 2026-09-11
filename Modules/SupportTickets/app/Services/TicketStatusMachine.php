<?php

namespace Modules\SupportTickets\Services;

use Modules\SupportTickets\Exceptions\SupportTicketException;
use Modules\SupportTickets\Support\TicketStatus;

class TicketStatusMachine
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        TicketStatus::DRAFT => [TicketStatus::NEW, TicketStatus::CANCELLED],
        TicketStatus::NEW => [
            TicketStatus::IN_PROGRESS,
            TicketStatus::AWAITING_YOU,
            TicketStatus::AWAITING_EKKLESIA,
            TicketStatus::RESOLVED,
            TicketStatus::CANCELLED,
        ],
        TicketStatus::IN_PROGRESS => [
            TicketStatus::AWAITING_YOU,
            TicketStatus::AWAITING_EKKLESIA,
            TicketStatus::RESOLVED,
            TicketStatus::CANCELLED,
        ],
        TicketStatus::AWAITING_YOU => [
            TicketStatus::IN_PROGRESS,
            TicketStatus::AWAITING_EKKLESIA,
            TicketStatus::RESOLVED,
            TicketStatus::CANCELLED,
        ],
        TicketStatus::AWAITING_EKKLESIA => [
            TicketStatus::IN_PROGRESS,
            TicketStatus::AWAITING_YOU,
            TicketStatus::RESOLVED,
            TicketStatus::CANCELLED,
        ],
        TicketStatus::RESOLVED => [TicketStatus::CLOSED, TicketStatus::IN_PROGRESS],
        TicketStatus::CLOSED => [TicketStatus::IN_PROGRESS],
        TicketStatus::CANCELLED => [],
    ];

    /** @var list<string> */
    private const REASON_REQUIRED = [
        TicketStatus::CANCELLED,
        TicketStatus::IN_PROGRESS, // when reopening from resolved/closed
    ];

    public function assertCanTransition(string $from, string $to, ?string $reason = null): void
    {
        if ($from === $to) {
            return;
        }

        $allowed = self::TRANSITIONS[$from] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw new SupportTicketException(
                "Cannot change ticket status from {$from} to {$to}.",
                422
            );
        }

        if ($this->requiresReason($from, $to) && trim((string) $reason) === '') {
            throw new SupportTicketException('A reason is required for this status change.', 422);
        }
    }

    private function requiresReason(string $from, string $to): bool
    {
        if ($to === TicketStatus::CANCELLED) {
            return true;
        }

        if (in_array($from, [TicketStatus::RESOLVED, TicketStatus::CLOSED], true)
            && $to === TicketStatus::IN_PROGRESS) {
            return true;
        }

        return false;
    }
}
