<?php

namespace Modules\Marketing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Marketing\Services\MarketingContentService;
use Modules\Marketing\Tests\Support\MarketingContentPayload;
use Tests\TestCase;

class PublicMarketingContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketing_content_returns_not_found_when_unpublished(): void
    {
        $response = $this->getJson('/api/public/marketing-content');

        $response->assertNotFound()
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonPath('data', null);
    }

    public function test_marketing_content_returns_published_payload(): void
    {
        $service = app(MarketingContentService::class);
        $service->publish(MarketingContentPayload::minimal(), null);

        $response = $this->getJson('/api/public/marketing-content');

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonPath('data.site.name', 'EkklesiaNexus');
    }
}
