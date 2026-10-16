<?php

namespace Modules\Marketing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Marketing\Models\WebSettingsAuditLog;
use Modules\Marketing\Services\MarketingContentService;
use Modules\Marketing\Tests\Support\MarketingContentPayload;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class MarketingSeoPublishTest extends TestCase
{
    use ActsAsTenantRoles;
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function seoBlock(): array
    {
        return [
            'schemaVersion' => 1,
            'canonicalOrigin' => 'http://localhost:3000',
            'defaultLocale' => 'en',
            'ogLocale' => 'en_US',
            'defaultOgImage' => '/images/og/og-preview.png',
            'organizationName' => 'EkklesiaNexus',
            'organizationDescription' => 'Church management.',
            'robots' => ['allowRoot' => true],
            'pages' => [
                'home' => [
                    'id' => 'home',
                    'name' => 'Homepage',
                    'path' => '/',
                    'seoTitle' => 'EkklesiaNexus — ChMS',
                    'seoDescription' => 'Church management software for parishes.',
                    'robotsIndex' => true,
                    'robotsFollow' => true,
                ],
                'privacy' => [
                    'id' => 'privacy',
                    'name' => 'Privacy',
                    'path' => '/privacy',
                    'seoTitle' => 'Privacy — EkklesiaNexus',
                    'seoDescription' => 'Privacy information.',
                    'robotsIndex' => false,
                    'robotsFollow' => false,
                ],
                'terms' => [
                    'id' => 'terms',
                    'name' => 'Terms',
                    'path' => '/terms',
                    'seoTitle' => 'Terms — EkklesiaNexus',
                    'seoDescription' => 'Terms information.',
                    'robotsIndex' => false,
                    'robotsFollow' => false,
                ],
            ],
        ];
    }

    public function test_super_admin_can_publish_seo_and_public_reads_it(): void
    {
        $this->asSuperAdmin();
        $payload = MarketingContentPayload::minimal();
        $payload['seo'] = $this->seoBlock();

        $this->postJson('/api/admin/marketing/content/publish', [
            'payload' => $payload,
        ])->assertCreated();

        $this->getJson('/api/public/marketing-content')
            ->assertOk()
            ->assertJsonPath('data.seo.pages.home.seoTitle', 'EkklesiaNexus — ChMS')
            ->assertJsonPath('data.site.seoTitle', 'EkklesiaNexus — ChMS');
    }

    public function test_publish_rejects_privacy_indexing(): void
    {
        $this->asSuperAdmin();
        $payload = MarketingContentPayload::minimal();
        $seo = $this->seoBlock();
        $seo['pages']['privacy']['robotsIndex'] = true;
        $payload['seo'] = $seo;

        $this->postJson('/api/admin/marketing/content/publish', [
            'payload' => $payload,
        ])->assertStatus(422);
    }

    public function test_publish_rejects_disallowed_canonical_origin(): void
    {
        $this->asSuperAdmin();
        $payload = MarketingContentPayload::minimal();
        $seo = $this->seoBlock();
        $seo['canonicalOrigin'] = 'https://evil.example';
        $payload['seo'] = $seo;

        $this->postJson('/api/admin/marketing/content/publish', [
            'payload' => $payload,
        ])->assertStatus(422);
    }

    public function test_publish_accepts_official_website_origin(): void
    {
        $this->asSuperAdmin();
        $payload = MarketingContentPayload::minimal();
        $seo = $this->seoBlock();
        $seo['canonicalOrigin'] = 'https://ekklesianexus.com';
        $payload['seo'] = $seo;

        $this->postJson('/api/admin/marketing/content/publish', [
            'payload' => $payload,
        ])->assertCreated();

        $this->getJson('/api/public/marketing-content')
            ->assertOk()
            ->assertJsonPath('data.seo.canonicalOrigin', 'https://ekklesianexus.com');
    }

    public function test_publish_rejects_legacy_marketing_domain(): void
    {
        $this->asSuperAdmin();
        $payload = MarketingContentPayload::minimal();
        $seo = $this->seoBlock();
        $seo['canonicalOrigin'] = 'https://ekklesianexus.in';
        $payload['seo'] = $seo;

        $this->postJson('/api/admin/marketing/content/publish', [
            'payload' => $payload,
        ])->assertStatus(422);
    }

    public function test_publish_invalidates_public_cache(): void
    {
        $this->asSuperAdmin();
        $service = app(MarketingContentService::class);
        $service->publish(MarketingContentPayload::minimal(), null);

        Cache::put(MarketingContentService::CACHE_KEY_PUBLISHED, ['stale' => true], 60);

        $payload = MarketingContentPayload::minimal();
        $payload['seo'] = $this->seoBlock();
        $this->postJson('/api/admin/marketing/content/publish', ['payload' => $payload])->assertCreated();

        $this->getJson('/api/public/marketing-content')
            ->assertOk()
            ->assertJsonPath('data.seo.pages.home.seoTitle', 'EkklesiaNexus — ChMS');
    }

    public function test_publish_writes_audit_log_when_seo_changes(): void
    {
        $this->asSuperAdmin();
        $payload = MarketingContentPayload::minimal();
        $payload['seo'] = $this->seoBlock();

        $this->postJson('/api/admin/marketing/content/publish', ['payload' => $payload])->assertCreated();

        $this->assertTrue(
            WebSettingsAuditLog::query()->where('action', 'marketing.content.published')->exists()
        );
    }

    public function test_publish_accepts_homepage_seo_description_of_325_characters(): void
    {
        $this->asSuperAdmin();
        $payload = MarketingContentPayload::minimal();
        $seo = $this->seoBlock();
        $description = 'Simplify parish administration with EkklesiaNexus, an integrated church management platform designed for Catholic parishes. Manage families, members, sacramental registers, Mass intentions, ministries, and church finances in one connected system—streamlining daily operations and helping your parish community stay connected.';
        $this->assertSame(325, mb_strlen($description));
        $seo['pages']['home']['seoDescription'] = $description;
        $payload['seo'] = $seo;

        $this->postJson('/api/admin/marketing/content/publish', ['payload' => $payload])
            ->assertCreated();

        $this->getJson('/api/public/marketing-content')
            ->assertOk()
            ->assertJsonPath('data.site.seoDescription', $description);
    }

    public function test_publish_rejects_html_in_organization_name(): void
    {
        $this->asSuperAdmin();
        $payload = MarketingContentPayload::minimal();
        $seo = $this->seoBlock();
        $seo['organizationName'] = '<script>alert(1)</script>';
        $payload['seo'] = $seo;

        $this->postJson('/api/admin/marketing/content/publish', ['payload' => $payload])
            ->assertStatus(422);
    }

    public function test_publish_accepts_optional_seo_page_overrides(): void
    {
        $this->asSuperAdmin();
        $payload = MarketingContentPayload::minimal();
        $seo = $this->seoBlock();
        $seo['pages']['features'] = [
            'id' => 'features',
            'name' => 'Features hub',
            'path' => '/features',
            'seoTitle' => 'Parish software features — EkklesiaNexus',
            'seoDescription' => 'Verified modules for Catholic parish office workflows and stewardship.',
            'robotsIndex' => true,
            'robotsFollow' => true,
        ];
        $payload['seo'] = $seo;

        $this->postJson('/api/admin/marketing/content/publish', ['payload' => $payload])
            ->assertCreated();
    }

    public function test_publish_rejects_duplicate_seo_titles(): void
    {
        $this->asSuperAdmin();
        $payload = MarketingContentPayload::minimal();
        $seo = $this->seoBlock();
        $seo['pages']['about'] = [
            'id' => 'about',
            'name' => 'About',
            'path' => '/about',
            'seoTitle' => $seo['pages']['home']['seoTitle'],
            'seoDescription' => 'About the product.',
            'robotsIndex' => true,
            'robotsFollow' => true,
        ];
        $payload['seo'] = $seo;

        $this->postJson('/api/admin/marketing/content/publish', ['payload' => $payload])
            ->assertStatus(422);
    }

    public function test_content_publish_calls_marketing_site_revalidate(): void
    {
        config([
            'marketing.plan_catalog.revalidate_secret' => 'test-revalidate-secret',
            'marketing.plan_catalog.site_url' => 'http://localhost:3010',
        ]);

        Http::fake([
            'localhost:3010/api/revalidate-plans' => Http::response(['revalidated' => true], 200),
        ]);

        $this->asSuperAdmin();
        $payload = MarketingContentPayload::minimal();
        $payload['seo'] = $this->seoBlock();

        $this->postJson('/api/admin/marketing/content/publish', ['payload' => $payload])
            ->assertCreated();

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:3010/api/revalidate-plans'
                && $request->hasHeader('X-Revalidate-Secret', 'test-revalidate-secret');
        });
    }
}
