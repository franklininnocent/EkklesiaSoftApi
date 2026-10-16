<?php

namespace Modules\SupportAccess\Contracts;

/**
 * Optional external helpdesk connector (Zendesk/Jira/etc.).
 * Bound to a Null adapter until a real connector is configured.
 */
interface ExternalHelpdeskTicketAdapter
{
    /**
     * @throws \RuntimeException when the ticket is missing or invalid
     */
    public function assertTicketExists(string $ticketRef): void;
}
