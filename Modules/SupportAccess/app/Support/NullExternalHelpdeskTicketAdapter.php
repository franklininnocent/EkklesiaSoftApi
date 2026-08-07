<?php

namespace Modules\SupportAccess\Support;

use Modules\SupportAccess\Contracts\ExternalHelpdeskTicketAdapter;
use RuntimeException;

/**
 * Fail-closed stub until a real helpdesk connector is registered.
 */
class NullExternalHelpdeskTicketAdapter implements ExternalHelpdeskTicketAdapter
{
    public function assertTicketExists(string $ticketRef): void
    {
        throw new RuntimeException(
            'External helpdesk ticket validation is not configured. Set ticket_validation_mode to off or required_format, or bind a real adapter.'
        );
    }
}
