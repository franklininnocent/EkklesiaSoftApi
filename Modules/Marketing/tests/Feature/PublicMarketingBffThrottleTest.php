<?php

namespace Modules\Marketing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PublicMarketingBffThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'marketing.bff.shared_secret' => 'test-bff-secret',
            'tenants.api.rate_limit.enabled' => true,
            'tenants.api.rate_limit.buckets.public_forms' => [
                'max_attempts' => 3,
                'decay_seconds' => 60,
            ],
        ]);
        RateLimiter::clear('api:marketing_bff_forms:tplatform:uguest:203.0.113.40');
    }

    public function test_missing_secret_is_unauthorized(): void
    {
        $this->postJson('/api/public/marketing-bff-throttle', [
            'bucket' => 'marketing_bff_forms',
            'client_ip' => '203.0.113.40',
        ])->assertUnauthorized();
    }

    public function test_spoofed_secret_is_rejected(): void
    {
        $this->postJson('/api/public/marketing-bff-throttle', [
            'bucket' => 'marketing_bff_forms',
            'client_ip' => '203.0.113.40',
        ], [
            'X-Marketing-Proxy-Secret' => 'wrong-secret',
        ])->assertUnauthorized();
    }

    public function test_invalid_ip_is_rejected(): void
    {
        $this->postJson('/api/public/marketing-bff-throttle', [
            'bucket' => 'marketing_bff_forms',
            'client_ip' => 'not-an-ip',
        ], [
            'X-Marketing-Proxy-Secret' => 'test-bff-secret',
        ])->assertUnprocessable();
    }

    public function test_shared_counter_limits_across_calls(): void
    {
        $headers = ['X-Marketing-Proxy-Secret' => 'test-bff-secret'];
        $payload = [
            'bucket' => 'marketing_bff_forms',
            'client_ip' => '203.0.113.40',
        ];

        $this->postJson('/api/public/marketing-bff-throttle', $payload, $headers)->assertOk();
        $this->postJson('/api/public/marketing-bff-throttle', $payload, $headers)->assertOk();
        $this->postJson('/api/public/marketing-bff-throttle', $payload, $headers)->assertOk();
        $this->postJson('/api/public/marketing-bff-throttle', $payload, $headers)->assertStatus(429);

        $this->postJson('/api/public/marketing-bff-throttle', [
            'bucket' => 'marketing_bff_forms',
            'client_ip' => '203.0.113.41',
        ], $headers)->assertOk();
    }
}
