<?php

namespace Modules\SupportTickets\Support;

final class TicketEventType
{
    public const CREATED = 'ticket_created';

    public const SUBMITTED = 'ticket_submitted';

    public const STATUS_CHANGED = 'status_changed';

    public const PRIORITY_CHANGED = 'priority_changed';

    public const QUEUE_CHANGED = 'queue_changed';

    public const ASSIGNED = 'assignment_changed';

    public const PUBLIC_COMMENT = 'public_comment';

    public const INTERNAL_NOTE = 'internal_note';

    public const PARTICIPANT_ADDED = 'participant_added';

    public const PARTICIPANT_REMOVED = 'participant_removed';

    public const ATTACHMENT_UPLOADED = 'attachment_uploaded';

    public const RESOLUTION = 'resolution';

    public const REOPENED = 'reopened';

    public const CANCELLED = 'cancelled';

    public const CLOSED = 'closed';
}
