<?php

namespace Modules\Tenants\Tests\Unit;

use Illuminate\Http\Request;
use Modules\Tenants\Support\SubscriptionAccessRoutePolicy;
use Tests\TestCase;

class SubscriptionAccessRoutePolicyTest extends TestCase
{
    public function test_restricted_generation_matches_download_but_not_list(): void
    {
        $policy = new SubscriptionAccessRoutePolicy(
            [],
            [],
            ['GET:api/tenant/donations/reports/exports/*/download'],
        );

        $list = Request::create('/api/tenant/donations/reports/exports', 'GET');
        $download = Request::create('/api/tenant/donations/reports/exports/abc/download', 'GET');

        $this->assertFalse($policy->isRestrictedGeneration($list));
        $this->assertTrue($policy->isRestrictedGeneration($download));
    }

    public function test_recovery_matches_parish_support_ticket_routes(): void
    {
        $policy = SubscriptionAccessRoutePolicy::fromConfig();

        $tickets = Request::create('/api/tenant/support/tickets', 'GET');
        $create = Request::create('/api/tenant/support/tickets', 'POST');
        $comment = Request::create('/api/tenant/support/tickets/abc/comments', 'POST');
        $ops = Request::create('/api/support/tickets', 'GET');

        $this->assertTrue($policy->isRecovery($tickets));
        $this->assertTrue($policy->isRecovery($create));
        $this->assertTrue($policy->isRecovery($comment));
        $this->assertFalse($policy->isRecovery($ops));
    }
}
