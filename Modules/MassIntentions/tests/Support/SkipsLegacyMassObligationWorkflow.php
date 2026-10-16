<?php

namespace Modules\MassIntentions\Tests\Support;

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\Group;

#[Group('legacy-mass-obligation')]
trait SkipsLegacyMassObligationWorkflow
{
    #[Before]
    public function skipLegacyMassObligationWorkflow(): void
    {
        $this->markTestSkipped('Legacy mass obligation workflow (accept/schedule/said) is paused; office open/closed model is active.');
    }
}
