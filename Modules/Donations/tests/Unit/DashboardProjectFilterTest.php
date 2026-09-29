<?php

namespace Modules\Donations\Tests\Unit;

use InvalidArgumentException;
use Modules\Donations\Support\DashboardProjectFilter;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use PHPUnit\Framework\Attributes\Test;

class DashboardProjectFilterTest extends DonationsCertificationTestCase
{
    #[Test]
    public function empty_input_is_no_filter(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $filter = DashboardProjectFilter::resolve((int) $ctx['tenant']->id, null);

        $this->assertFalse($filter->isActive);
    }

    #[Test]
    public function rejects_project_from_another_tenant(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);

        $this->expectException(InvalidArgumentException::class);
        DashboardProjectFilter::resolve((int) $ctx['tenant']->id, 'not-a-real-project-id');
    }
}
