<?php

namespace Modules\Tenants\Tests\Unit;

use Illuminate\Http\Request;
use Modules\Tenants\Support\SubscriptionRouteAllowlist;
use Tests\TestCase;

class SubscriptionRouteAllowlistTest extends TestCase
{
    public function test_matches_exact_and_wildcard_paths(): void
    {
        $allowlist = new SubscriptionRouteAllowlist([
            'api/auth/refresh',
            'api/donations/webhooks/*',
            'api/persons/matches',
        ]);

        $refresh = Request::create('/api/auth/refresh', 'POST');
        $webhook = Request::create('/api/donations/webhooks/razorpay', 'POST');
        $matches = Request::create('/api/persons/matches', 'POST');
        $blocked = Request::create('/api/families', 'POST');

        $this->assertTrue($allowlist->allows($refresh));
        $this->assertTrue($allowlist->allows($webhook));
        $this->assertTrue($allowlist->allows($matches));
        $this->assertFalse($allowlist->allows($blocked));
    }

    public function test_application_access_revoke_is_allowlisted_from_config(): void
    {
        $allowlist = SubscriptionRouteAllowlist::fromConfig();

        $revoke = Request::create('/api/admin/application-access/sessions/abc/revoke', 'POST');
        $block = Request::create('/api/admin/application-access/ip-blocks', 'POST');

        $this->assertTrue($allowlist->allows($revoke));
        $this->assertTrue($allowlist->allows($block));
    }
}
