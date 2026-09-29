<?php

namespace Modules\Notifications\Support;

use Modules\Authentication\Models\User;

/**
 * Primitive publish intent — modules pass strings/ids only, never models from other modules.
 */
final readonly class NotificationIntent
{
    /**
     * @param  list<int>  $explicitRecipientIds
     * @param  array<string, scalar|null>  $data
     */
    public function __construct(
        public string $definitionCode,
        public ?User $actor,
        public ?string $subjectType,
        public ?string $subjectId,
        public ?int $tenantId,
        public InboxScope $scope,
        public string $occurrenceId,
        public array $data = [],
        public array $explicitRecipientIds = [],
        public ?string $collapseKey = null,
        public string $actionStatus = 'none',
        public bool $isMention = false,
    ) {
    }
}
