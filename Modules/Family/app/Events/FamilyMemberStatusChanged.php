<?php

namespace Modules\Family\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FamilyMemberStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $familyMemberId,
        public readonly string $previousStatus,
        public readonly string $newStatus,
        public readonly string $effectiveDate,
    ) {
    }
}
