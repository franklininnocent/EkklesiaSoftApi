<?php

namespace Modules\SupportTickets\Exceptions;

use RuntimeException;

class SupportTicketException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
