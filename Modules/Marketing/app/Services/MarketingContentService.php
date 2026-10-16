<?php

namespace Modules\Marketing\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Authentication\Models\User;
use Modules\Marketing\Models\MarketingContentRevision;
use Modules\Marketing\Models\WebSettingsAuditLog;

class MarketingContentService
{
    public const CACHE_KEY_PUBLISHED = 'marketing.content.published';

    public function __construct(
        private readonly MarketingSeoValidator $seoValidator,
        private readonly MarketingPublicLinkValidator $linkValidator,
        private readonly MarketingSiteRevalidator $siteRevalidator,
    ) {
    }
    /** @var list<string> */
    public const REQUIRED_PAYLOAD_KEYS = [
        'site',
        'navigation',
        'hero',
        'valueStrip',
        'why',
        'church360',
        'featureCategories',
        'solutions',
        'reports',
        'workflow',
        'advantages',
        'audiences',
        'finalCta',
        'screenshots',
        'footer',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertValidPayload(array $payload): void
    {
        foreach (self::REQUIRED_PAYLOAD_KEYS as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new \InvalidArgumentException("Marketing content payload missing required key: {$key}");
            }
        }

        if (! is_array($payload['site'] ?? null)) {
            throw new \InvalidArgumentException('Marketing content payload.site must be an object.');
        }

        $this->linkValidator->assertValidLinks($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function prepareForPersist(array $payload, bool $isPublish): array
    {
        $this->assertValidPayload($payload);
        $this->seoValidator->validateAndNormalizeSeo($payload, $isPublish);

        return $payload;
    }

    public static function forgetPublishedCache(): void
    {
        Cache::forget(self::CACHE_KEY_PUBLISHED);
    }

    public function getPublishedRevision(): ?MarketingContentRevision
    {
        return MarketingContentRevision::query()
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPublishedPayload(): ?array
    {
        $revision = $this->getPublishedRevision();

        return $revision?->payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(array $payload, ?User $publisher, ?string $notes = null): MarketingContentRevision
    {
        $beforeSeo = $this->extractSeoAuditSlice($payload);
        $payload = $this->prepareForPersist($payload, true);

        $revision = MarketingContentRevision::query()->create([
            'schema_version' => '1',
            'payload' => $payload,
            'notes' => $notes,
            'published_at' => now(),
            'published_by' => $publisher?->id,
        ]);

        self::forgetPublishedCache();

        Log::info('marketing.content.published', [
            'revision_id' => $revision->id,
            'published_by' => $publisher?->id,
        ]);

        $this->auditMarketingContent($publisher, 'marketing.content.published', $beforeSeo, $this->extractSeoAuditSlice($payload));

        $this->siteRevalidator->revalidateMarketingSite();

        return $revision;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveDraft(array $payload, ?User $author, ?string $notes = null): MarketingContentRevision
    {
        $beforeSeo = $this->extractSeoAuditSlice($payload);
        $payload = $this->prepareForPersist($payload, false);

        $revision = MarketingContentRevision::query()->create([
            'schema_version' => '1',
            'payload' => $payload,
            'notes' => $notes,
            'published_at' => null,
            'published_by' => $author?->id,
        ]);

        Log::info('marketing.content.draft_saved', [
            'revision_id' => $revision->id,
            'saved_by' => $author?->id,
        ]);

        $this->auditMarketingContent($author, 'marketing.content.draft_saved', $beforeSeo, $this->extractSeoAuditSlice($payload));

        return $revision;
    }

    public function getLatestDraft(): ?MarketingContentRevision
    {
        return MarketingContentRevision::query()
            ->whereNull('published_at')
            ->orderByDesc('id')
            ->first();
    }

    public function publishDraftRevision(int $revisionId, ?User $publisher): MarketingContentRevision
    {
        $draft = MarketingContentRevision::query()
            ->whereNull('published_at')
            ->whereKey($revisionId)
            ->first();

        if ($draft === null) {
            throw new \InvalidArgumentException('Draft revision not found or already published.');
        }

        return $this->publish($draft->payload, $publisher, $draft->notes);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractSeoAuditSlice(array $payload): array
    {
        $site = is_array($payload['site'] ?? null) ? $payload['site'] : [];
        $seo = is_array($payload['seo'] ?? null) ? $payload['seo'] : [];

        return [
            'site_seo_title' => $site['seoTitle'] ?? null,
            'site_seo_description' => $site['seoDescription'] ?? null,
            'seo' => $seo,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function auditMarketingContent(?User $actor, string $action, array $before, array $after): void
    {
        if ($actor === null) {
            return;
        }

        if ($before == $after) {
            return;
        }

        WebSettingsAuditLog::query()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'changes' => ['from' => $before, 'to' => $after],
        ]);
    }
}
