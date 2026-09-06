<?php

namespace Modules\Family\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FamilyBccRelocated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $familyId,
        public readonly ?string $fromBccId,
        public readonly string $toBccId,
        public readonly string $transitionId,
        public readonly string $effectiveDate,
    ) {}
}
