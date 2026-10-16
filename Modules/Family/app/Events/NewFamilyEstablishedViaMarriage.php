<?php

namespace Modules\Family\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewFamilyEstablishedViaMarriage implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $memberIds
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $newFamilyId,
        public readonly array $memberIds,
        public readonly string $transitionId,
        public readonly string $effectiveDate,
    ) {}
}
