<?php

namespace Modules\Marketing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Marketing\Tests\Support\MarketingContentPayload;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class MarketingPublicLinkValidationTest extends TestCase
{
    use ActsAsTenantRoles;
    use RefreshDatabase;

    public function test_publish_rejects_external_navigation_url(): void
    {
        $this->asSuperAdmin();
        $payload = MarketingContentPayload::minimal();
        $payload['navigation'] = [
            ['label' => 'Evil', 'href' => 'https://evil.example'],
        ];

        $this->postJson('/api/admin/marketing/content/publish', [
            'payload' => $payload,
        ])->assertStatus(422);
    }
}
