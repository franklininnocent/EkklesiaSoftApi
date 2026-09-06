<?php

namespace Modules\Family\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MemberJoinedHouseholdViaMarriage implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $targetFamilyId,
        public readonly string $joiningMemberId,
        public readonly string $transitionId,
        public readonly string $effectiveDate,
    ) {}
}
