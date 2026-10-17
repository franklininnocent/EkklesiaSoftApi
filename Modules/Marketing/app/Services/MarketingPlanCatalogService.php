<?php

namespace Modules\Marketing\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Authentication\Models\User;
use Modules\Marketing\Models\MarketingPlanCatalogPublication;
use Modules\Marketing\Models\MarketingPlanCatalogRevision;
use Modules\Marketing\Models\WebSettingsAuditLog;
use Modules\Marketing\Support\MarketingPlanCatalogCache;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Services\Catalog\PlanVersionContentHasher;
use Modules\Subscriptions\Services\SubscriptionPolicyService;
use Modules\Subscriptions\Services\SubscriptionPresenter;
use Modules\Subscriptions\Support\EntitlementCacheVersion;

class MarketingPlanCatalogService
{
    public function __construct(
        private readonly SubscriptionPresenter $presenter,
        private readonly SubscriptionPolicyService $policies,
        private readonly PlanVersionContentHasher $hasher,
        private readonly MarketingPlanCatalogValidator $validator,
        private readonly MarketingSiteRevalidator $revalidator,
        private readonly MarketingPlanFeatureVisibility $visibility,
        private readonly MarketingRegionalPricesAssembler $regionalPrices,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function getPublishedPlans(): array
    {
        $ttl = max(0, (int) config('marketing.plan_catalog.public_cache_seconds', 300));

        $load = function (): array {
            $publication = $this->publicationRow();
            if ($publication->published_revision_id === null) {
                return [];
            }

            $revision = MarketingPlanCatalogRevision::query()->find($publication->published_revision_id);
            if ($revision === null || $revision->status !== MarketingPlanCatalogRevision::STATUS_PUBLISHED) {
                return [];
            }

            return $this->publicPlansFromRevision($revision);
        };

        if ($ttl > 0) {
            return Cache::remember(MarketingPlanCatalogCache::KEY_PUBLISHED, $ttl, $load);
        }

        return $load();
    }

    /**
     * @return array<string, mixed>
     */
    public function getStatusOverview(): array
    {
        $publication = $this->publicationRow();
        $published = $publication->published_revision_id
            ? MarketingPlanCatalogRevision::query()->find($publication->published_revision_id)
            : null;

        $draft = MarketingPlanCatalogRevision::query()
            ->where('status', MarketingPlanCatalogRevision::STATUS_DRAFT)
            ->orderByDesc('id')
            ->first();

        $live = $this->buildLiveSnapshot();
        $readiness = $this->mergeCatalogValidation($live['plans'], $live);

        return [
            'published' => $published ? $this->revisionSummary($published) : null,
            'draft' => $draft ? $this->revisionSummary($draft) : null,
            'readiness' => [
                'live_plan_count' => count($live['plans']),
                'plan_codes' => array_column($live['plans'], 'code'),
                'blocking_errors' => $readiness['errors'],
                'warnings' => $readiness['warnings'],
                'can_generate_preview' => $readiness['errors'] === [],
            ],
            'live_fingerprint' => $live['source_fingerprint'],
            'published_fingerprint' => $published?->source_fingerprint,
            'draft_fingerprint' => $draft?->source_fingerprint,
            'drift_from_published' => $published !== null && $published->source_fingerprint !== $live['source_fingerprint'],
            'drift_from_draft' => $draft !== null && $draft->source_fingerprint !== $live['source_fingerprint'],
            'live_plan_count' => count($live['plans']),
            'revalidate_status' => $publication->revalidate_status,
            'revalidate_error' => $publication->revalidate_error,
        ];
    }

    public function generatePreview(User $actor, ?string $notes = null): MarketingPlanCatalogRevision
    {
        $snapshot = $this->buildLiveSnapshot();
        $validation = $this->mergeCatalogValidation($snapshot['plans'], $snapshot);
        if ($validation['errors'] !== []) {
            throw new InvalidArgumentException(implode(' ', $validation['errors']));
        }

        $previous = MarketingPlanCatalogRevision::query()
            ->where('status', MarketingPlanCatalogRevision::STATUS_DRAFT)
            ->orderByDesc('id')
            ->first();
        if ($previous === null) {
            $publishedId = $this->publicationRow()->published_revision_id;
            $previous = $publishedId
                ? MarketingPlanCatalogRevision::query()->find($publishedId)
                : null;
        }
        $hidden = $this->visibility->carryForwardHidden($this->hiddenMap($previous), $snapshot['plans']);
        $hiddenPlans = $this->visibility->carryForwardHiddenPlans($this->hiddenPlans($previous), $snapshot['plans']);
        $publicPlans = $this->visibility->applyToPublicPayload($snapshot['plans'], $hidden, $hiddenPlans);

        return DB::transaction(function () use ($snapshot, $actor, $notes, $validation, $hidden, $hiddenPlans, $publicPlans): MarketingPlanCatalogRevision {
            $revisionNumber = (int) MarketingPlanCatalogRevision::query()->max('revision_number') + 1;

            $revision = MarketingPlanCatalogRevision::query()->create([
                'revision_number' => $revisionNumber,
                'status' => MarketingPlanCatalogRevision::STATUS_DRAFT,
                'payload' => $publicPlans,
                'eligible_payload' => $snapshot['plans'],
                'feature_visibility' => $this->visibilityRecord($hidden, $hiddenPlans),
                'source_refs' => $snapshot['source_refs'],
                'source_fingerprint' => $snapshot['source_fingerprint'],
                'payload_hash' => $this->payloadHash($publicPlans),
                'validation_report' => $validation,
                'generated_by' => $actor->id,
                'generated_at' => now(),
                'notes' => $notes,
            ]);

            $this->audit($actor, 'marketing.plan_catalog.preview_generated', [
                'revision_id' => $revision->id,
                'revision_number' => $revision->revision_number,
                'plan_codes' => array_column($publicPlans, 'code'),
                'hidden' => $hidden,
                'hidden_plans' => $hiddenPlans,
            ]);

            return $revision;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function getFeatureVisibilityEditor(int $revisionId): array
    {
        $revision = MarketingPlanCatalogRevision::query()->find($revisionId);
        if ($revision === null) {
            throw new InvalidArgumentException('Revision not found.');
        }

        $eligible = $this->eligiblePlans($revision);
        $hidden = $this->hiddenMap($revision);
        $hiddenPlans = $this->hiddenPlans($revision);
        $publishedHidden = $this->publishedHiddenMap();
        $publishedHiddenPlans = $this->publishedHiddenPlans();
        $view = $this->visibility->editorView($eligible, $hidden, $publishedHidden, $hiddenPlans, $publishedHiddenPlans);

        return [
            'revision_id' => $revision->id,
            'revision_number' => $revision->revision_number,
            'status' => $revision->status,
            'payload_hash' => $revision->payload_hash,
            'editable' => $revision->status === MarketingPlanCatalogRevision::STATUS_DRAFT,
            ...$view,
        ];
    }

    /**
     * @param  array<string, mixed>  $visibilityInput
     */
    public function updateFeatureVisibility(
        User $actor,
        int $revisionId,
        string $expectedPayloadHash,
        array $visibilityInput,
    ): MarketingPlanCatalogRevision {
        return DB::transaction(function () use ($actor, $revisionId, $expectedPayloadHash, $visibilityInput): MarketingPlanCatalogRevision {
            $revision = MarketingPlanCatalogRevision::query()->whereKey($revisionId)->lockForUpdate()->first();
            if ($revision === null) {
                throw new InvalidArgumentException('Revision not found.');
            }
            if ($revision->status !== MarketingPlanCatalogRevision::STATUS_DRAFT) {
                throw new InvalidArgumentException('Feature visibility can only be edited on a draft revision.');
            }
            if ($revision->payload_hash !== $expectedPayloadHash) {
                throw new InvalidArgumentException('This draft changed since you loaded it. Refresh and try again.');
            }

            $eligible = $this->eligiblePlans($revision);
            $hidden = $this->visibility->normalizeHiddenMap($visibilityInput);
            $hiddenPlans = array_key_exists('hidden_plans', $visibilityInput)
                ? $this->visibility->normalizeHiddenPlans($visibilityInput)
                : $this->hiddenPlans($revision);
            $this->visibility->assertHiddenMapAllowed($eligible, $hidden);
            $this->visibility->assertHiddenPlansAllowed($eligible, $hiddenPlans);
            $this->visibility->assertVisibleFeaturesArePubliclyAllowed($eligible, $hidden, $hiddenPlans);

            $publicPlans = $this->visibility->applyToPublicPayload($eligible, $hidden, $hiddenPlans);
            $validation = $this->validator->validate($publicPlans);
            if ($validation['errors'] !== []) {
                throw new InvalidArgumentException(implode(' ', $validation['errors']));
            }

            $beforeHidden = $this->hiddenMap($revision);
            $beforeHiddenPlans = $this->hiddenPlans($revision);
            $revision->forceFill([
                'payload' => $publicPlans,
                'feature_visibility' => $this->visibilityRecord($hidden, $hiddenPlans),
                'payload_hash' => $this->payloadHash($publicPlans),
                'validation_report' => $validation,
            ])->save();

            $this->audit($actor, 'marketing.plan_catalog.feature_visibility_updated', [
                'revision_id' => $revision->id,
                'revision_number' => $revision->revision_number,
                'changes' => $this->visibility->diffHiddenMaps($beforeHidden, $hidden)
                    + ['plans' => $this->visibility->diffHiddenPlans($beforeHiddenPlans, $hiddenPlans)],
                'hidden' => $hidden,
                'hidden_plans' => $hiddenPlans,
            ]);

            return $revision->fresh();
        });
    }

    /**
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function validateRevision(MarketingPlanCatalogRevision $revision): array
    {
        $plans = is_array($revision->payload) ? $revision->payload : [];
        $report = $this->validator->validate($plans);
        $revision->forceFill(['validation_report' => $report])->save();

        return $report;
    }

    public function publishRevision(
        User $actor,
        int $revisionId,
        string $expectedPayloadHash,
        bool $skipLiveFingerprintCheck = false,
    ): MarketingPlanCatalogRevision {
        return DB::transaction(function () use ($actor, $revisionId, $expectedPayloadHash, $skipLiveFingerprintCheck): MarketingPlanCatalogRevision {
            $publication = MarketingPlanCatalogPublication::query()->whereKey(1)->lockForUpdate()->firstOrFail();

            $revision = MarketingPlanCatalogRevision::query()->whereKey($revisionId)->lockForUpdate()->first();
            if ($revision === null) {
                throw new InvalidArgumentException('Revision not found.');
            }

            if ($revision->status !== MarketingPlanCatalogRevision::STATUS_DRAFT) {
                throw new InvalidArgumentException('Only draft revisions can be approved for publication.');
            }

            if ($revision->payload_hash !== $expectedPayloadHash) {
                throw new InvalidArgumentException('The revision payload has changed. Generate a new preview and try again.');
            }

            $previousHidden = $this->publishedHiddenMap();
            $previousHiddenPlans = $this->publishedHiddenPlans();
            $eligible = $this->eligiblePlans($revision);
            $hidden = $this->hiddenMap($revision);
            $hiddenPlans = $this->hiddenPlans($revision);
            $this->visibility->assertHiddenMapAllowed($eligible, $hidden);
            $this->visibility->assertHiddenPlansAllowed($eligible, $hiddenPlans);
            $this->visibility->assertVisibleFeaturesArePubliclyAllowed($eligible, $hidden, $hiddenPlans);
            $plans = $this->visibility->applyToPublicPayload($eligible, $hidden, $hiddenPlans);
            if ($this->payloadHash($plans) !== $expectedPayloadHash) {
                throw new InvalidArgumentException('The draft visibility no longer matches this preview. Save visibility and try again.');
            }
            $validation = $this->validator->validate($plans);
            if ($validation['errors'] !== []) {
                throw new InvalidArgumentException('Validation failed: '.implode(' ', $validation['errors']));
            }
            $revision->forceFill(['payload' => $plans]);

            if (! $skipLiveFingerprintCheck) {
                $liveFingerprint = $this->buildLiveSnapshot()['source_fingerprint'];
                if ($liveFingerprint !== $revision->source_fingerprint) {
                    throw new InvalidArgumentException('Subscription catalog changed after this preview was generated. Generate a new preview.');
                }
            }

            MarketingPlanCatalogRevision::query()
                ->where('status', MarketingPlanCatalogRevision::STATUS_PUBLISHED)
                ->update(['status' => MarketingPlanCatalogRevision::STATUS_SUPERSEDED]);

            $revision->forceFill([
                'status' => MarketingPlanCatalogRevision::STATUS_PUBLISHED,
                'published_by' => $actor->id,
                'published_at' => now(),
                'validation_report' => $validation,
            ])->save();

            $publication->forceFill([
                'published_revision_id' => $revision->id,
                'published_at' => $revision->published_at,
                'published_by' => $actor->id,
            ])->save();

            $this->audit($actor, 'marketing.plan_catalog.published', [
                'revision_id' => $revision->id,
                'revision_number' => $revision->revision_number,
                'plan_codes' => array_column($plans, 'code'),
                'hidden' => $hidden,
                'hidden_plans' => $hiddenPlans,
                'visibility_changes' => $this->visibility->diffHiddenMaps($previousHidden, $hidden)
                    + ['plans' => $this->visibility->diffHiddenPlans($previousHiddenPlans, $hiddenPlans)],
            ]);

            return $revision->fresh();
        });
    }

    public function afterPublishCommit(MarketingPlanCatalogRevision $revision): void
    {
        MarketingPlanCatalogCache::forgetPublished();

        $result = $this->revalidator->revalidatePlans();
        MarketingPlanCatalogPublication::query()->whereKey(1)->update([
            'revalidate_status' => $result['status'],
            'revalidate_error' => $result['error'],
        ]);
    }

    public function republishHistorical(User $actor, int $revisionId): MarketingPlanCatalogRevision
    {
        $source = MarketingPlanCatalogRevision::query()->find($revisionId);
        if ($source === null || ! in_array($source->status, [
            MarketingPlanCatalogRevision::STATUS_PUBLISHED,
            MarketingPlanCatalogRevision::STATUS_SUPERSEDED,
        ], true)) {
            throw new InvalidArgumentException('Only a previously published revision can be restored.');
        }

        $plans = is_array($source->payload) ? $source->payload : [];
        $validation = $this->validator->validate($plans);
        if ($validation['errors'] !== []) {
            throw new InvalidArgumentException('Historical revision is no longer valid: '.implode(' ', $validation['errors']));
        }

        $draft = DB::transaction(function () use ($source, $actor, $plans, $validation): MarketingPlanCatalogRevision {
            $revisionNumber = (int) MarketingPlanCatalogRevision::query()->max('revision_number') + 1;

            return MarketingPlanCatalogRevision::query()->create([
                'revision_number' => $revisionNumber,
                'status' => MarketingPlanCatalogRevision::STATUS_DRAFT,
                'payload' => $plans,
                'eligible_payload' => $this->eligiblePlans($source),
                'feature_visibility' => $this->visibilityRecord($this->hiddenMap($source), $this->hiddenPlans($source)),
                'source_refs' => $source->source_refs,
                'source_fingerprint' => $source->source_fingerprint,
                'payload_hash' => $this->payloadHash($plans),
                'validation_report' => $validation,
                'generated_by' => $actor->id,
                'generated_at' => now(),
                'notes' => 'Restored from revision #'.$source->revision_number,
            ]);
        });

        $published = $this->publishRevision($actor, (int) $draft->id, $draft->payload_hash, true);
        $this->afterPublishCommit($published);

        return $published;
    }

    public function retryRevalidate(): array
    {
        $result = $this->revalidator->revalidatePlans();
        MarketingPlanCatalogPublication::query()->whereKey(1)->update([
            'revalidate_status' => $result['status'],
            'revalidate_error' => $result['error'],
        ]);

        return $result;
    }

    /**
     * @return array{token: string, expires_at: string, preview_path: string}
     */
    public function issuePreviewToken(User $actor, int $revisionId): array
    {
        $revision = MarketingPlanCatalogRevision::query()->find($revisionId);
        if ($revision === null || $revision->status !== MarketingPlanCatalogRevision::STATUS_DRAFT) {
            throw new InvalidArgumentException('Preview tokens are only issued for draft revisions.');
        }

        $plain = Str::random(48);
        $hash = hash('sha256', $plain);
        $ttl = max(30, (int) config('marketing.plan_catalog.preview_token_ttl_seconds', 120));
        $expires = now()->addSeconds($ttl);

        DB::table('marketing_plan_preview_tokens')->insert([
            'token_hash' => $hash,
            'revision_id' => $revision->id,
            'issued_by' => $actor->id,
            'expires_at' => $expires,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'token' => $plain,
            'expires_at' => $expires->toIso8601String(),
            'preview_path' => '/preview/plans?token='.urlencode($plain),
        ];
    }

    /**
     * Exchange a one-time URL token for a short-lived session token.
     *
     * @return array{plans: list<array<string, mixed>>, session: string, expires_in: int}
     */
    public function exchangePreviewToken(string $plainToken): array
    {
        $hash = hash('sha256', $plainToken);

        return DB::transaction(function () use ($hash) {
            $row = DB::table('marketing_plan_preview_tokens')
                ->where('token_hash', $hash)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($row === null || $row->used_at !== null) {
                throw new InvalidArgumentException('Preview token is invalid or expired.');
            }

            $revision = MarketingPlanCatalogRevision::query()->find($row->revision_id);
            if ($revision === null || $revision->status !== MarketingPlanCatalogRevision::STATUS_DRAFT) {
                throw new InvalidArgumentException('Preview token is invalid or expired.');
            }

            $sessionPlain = Str::random(48);
            $expiresAt = \Illuminate\Support\Carbon::parse($row->expires_at);
            DB::table('marketing_plan_preview_tokens')->where('id', $row->id)->update([
                'session_hash' => hash('sha256', $sessionPlain),
                'used_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'plans' => $this->publicPlansFromRevision($revision),
                'session' => $sessionPlain,
                'expires_in' => max(1, $expiresAt->getTimestamp() - time()),
            ];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function redeemPreviewSession(string $plainSession): array
    {
        $hash = hash('sha256', $plainSession);
        $row = DB::table('marketing_plan_preview_tokens')
            ->where('session_hash', $hash)
            ->where('expires_at', '>', now())
            ->first();

        if ($row === null) {
            throw new InvalidArgumentException('Preview token is invalid or expired.');
        }

        $revision = MarketingPlanCatalogRevision::query()->find($row->revision_id);
        if ($revision === null || $revision->status !== MarketingPlanCatalogRevision::STATUS_DRAFT) {
            throw new InvalidArgumentException('Preview token is invalid or expired.');
        }

        return $this->publicPlansFromRevision($revision);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function redeemPreviewToken(string $plainToken): array
    {
        return $this->exchangePreviewToken($plainToken)['plans'];
    }

    /**
     * @return array{plans: list<array<string, mixed>>, source_refs: list<array<string, mixed>>, source_fingerprint: string}
     */
    public function buildLiveSnapshot(): array
    {
        $plans = [];
        $sourceRefs = [];
        $regionalErrors = [];
        $regionalWarnings = [];

        $rows = Plan::query()->publiclyListed()
            ->with('activeVersion.entitlements.feature')
            ->orderBy('display_order')
            ->get()
            ->filter(fn (Plan $p) => $p->activeVersion !== null);

        foreach ($rows as $plan) {
            $version = $plan->activeVersion;
            $assembled = $this->regionalPrices->assemble($plan, $version);
            $regionalErrors = array_merge($regionalErrors, $assembled['errors']);
            $regionalWarnings = array_merge($regionalWarnings, $assembled['warnings']);

            $card = $this->presenter->publicPlan($plan, $version);
            if ($assembled['regional_prices'] !== []) {
                $card['regional_prices'] = $assembled['regional_prices'];
            }
            $plans[] = $this->validator->allowlistPlan($card);
            $sourceRefs[] = [
                'plan_code' => $plan->code,
                'plan_version_id' => (int) $version->id,
                'version_number' => (int) $version->version_number,
                'content_hash' => $version->content_hash ?: $this->hasher->hash($version),
            ];
        }

        usort($sourceRefs, static fn ($a, $b) => strcmp($a['plan_code'], $b['plan_code']));

        $fingerprint = $this->sourceFingerprint($sourceRefs);

        return [
            'plans' => $plans,
            'source_refs' => $sourceRefs,
            'source_fingerprint' => $fingerprint,
            'regional_errors' => $regionalErrors,
            'regional_warnings' => $regionalWarnings,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $plans
     * @param  array{regional_errors?: list<string>, regional_warnings?: list<string>}  $snapshotMeta
     * @return array{errors: list<string>, warnings: list<string>}
     */
    private function mergeCatalogValidation(array $plans, array $snapshotMeta = []): array
    {
        $validation = $this->validator->validate($plans);
        $validation['errors'] = array_merge(
            $validation['errors'],
            $snapshotMeta['regional_errors'] ?? [],
        );
        $validation['warnings'] = array_merge(
            $validation['warnings'],
            $snapshotMeta['regional_warnings'] ?? [],
        );

        return $validation;
    }

    /**
     * @param  list<array<string, mixed>>  $plans
     * @return array<string, mixed>
     */
    public function diffAgainstPublished(array $plans): array
    {
        $publication = $this->publicationRow();
        $current = [];
        if ($publication->published_revision_id) {
            $rev = MarketingPlanCatalogRevision::query()->find($publication->published_revision_id);
            $current = is_array($rev?->payload) ? $rev->payload : [];
        }

        $currentByCode = collect($current)->keyBy('code');
        $nextByCode = collect($plans)->keyBy('code');

        $added = $nextByCode->keys()->diff($currentByCode->keys())->values()->all();
        $removed = $currentByCode->keys()->diff($nextByCode->keys())->values()->all();
        $changed = [];

        foreach ($nextByCode as $code => $plan) {
            $prev = $currentByCode->get($code);
            if ($prev === null) {
                continue;
            }
            if ($this->payloadHash([$prev]) !== $this->payloadHash([$plan])) {
                $changed[] = $code;
            }
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
            'plan_count' => count($plans),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function publishReview(MarketingPlanCatalogRevision $revision): array
    {
        $plans = $this->publicPlansFromRevision($revision);
        $eligible = $this->eligiblePlans($revision);
        $hidden = $this->hiddenMap($revision);
        $hiddenPlans = $this->hiddenPlans($revision);

        return $this->diffAgainstPublished($plans) + [
            'visibility' => $this->visibility->diffHiddenMaps($this->publishedHiddenMap(), $hidden)
                + ['plans' => $this->visibility->diffHiddenPlans($this->publishedHiddenPlans(), $hiddenPlans)],
            'hidden_from_all' => $this->visibility->hiddenFromEveryPlan($eligible, $hidden, $hiddenPlans),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sourceRefs
     */
    private function sourceFingerprint(array $sourceRefs): string
    {
        $tax = $this->policies->platformTax();

        return hash('sha256', json_encode([
            'catalog_version' => EntitlementCacheVersion::catalog(),
            'source_refs' => $sourceRefs,
            'platform_tax' => $tax,
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $plans
     */
    public function payloadHash(array $plans): string
    {
        return hash('sha256', json_encode($plans));
    }

    private function publicationRow(): MarketingPlanCatalogPublication
    {
        return MarketingPlanCatalogPublication::query()->firstOrCreate(
            ['id' => 1],
            ['revalidate_status' => MarketingPlanCatalogPublication::REVALIDATE_NOT_REQUIRED],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function revisionSummary(MarketingPlanCatalogRevision $revision): array
    {
        $plans = is_array($revision->payload) ? $revision->payload : [];

        return [
            'id' => $revision->id,
            'revision_number' => $revision->revision_number,
            'status' => $revision->status,
            'payload_hash' => $revision->payload_hash,
            'source_fingerprint' => $revision->source_fingerprint,
            'plan_count' => count($plans),
            'plan_codes' => array_column($plans, 'code'),
            'generated_at' => $revision->generated_at?->toIso8601String(),
            'published_at' => $revision->published_at?->toIso8601String(),
            'validation_report' => $revision->validation_report,
            'hidden_feature_count' => array_sum(array_map('count', $this->hiddenMap($revision))),
            'hidden_plan_count' => count($this->hiddenPlans($revision)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function publicPlansFromRevision(MarketingPlanCatalogRevision $revision): array
    {
        $eligible = $this->eligiblePlans($revision);
        $hidden = $this->hiddenMap($revision);

        return array_values($this->visibility->applyToPublicPayload($eligible, $hidden, $this->hiddenPlans($revision)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eligiblePlans(?MarketingPlanCatalogRevision $revision): array
    {
        if ($revision === null) {
            return [];
        }
        $eligible = $revision->eligible_payload;
        if (! is_array($eligible) || $eligible === []) {
            $eligible = $revision->payload;
        }

        return is_array($eligible) ? array_values($eligible) : [];
    }

    /**
     * @return array<string, list<string>>
     */
    private function hiddenMap(?MarketingPlanCatalogRevision $revision): array
    {
        if ($revision === null) {
            return [];
        }

        $raw = is_array($revision->feature_visibility) ? $revision->feature_visibility : [];

        return $this->visibility->normalizeHiddenMap($raw);
    }

    /**
     * @return list<string>
     */
    private function hiddenPlans(?MarketingPlanCatalogRevision $revision): array
    {
        if ($revision === null) {
            return [];
        }

        $raw = is_array($revision->feature_visibility) ? $revision->feature_visibility : [];

        return $this->visibility->normalizeHiddenPlans($raw);
    }

    /**
     * @param  array<string, list<string>>  $hidden
     * @param  list<string>  $hiddenPlans
     * @return array{hidden: array<string, list<string>>, hidden_plans: list<string>}
     */
    private function visibilityRecord(array $hidden, array $hiddenPlans): array
    {
        return [
            'hidden' => $hidden,
            'hidden_plans' => $hiddenPlans,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function publishedHiddenMap(): array
    {
        $publication = $this->publicationRow();
        if ($publication->published_revision_id === null) {
            return [];
        }

        $published = MarketingPlanCatalogRevision::query()->find($publication->published_revision_id);

        return $this->hiddenMap($published);
    }

    /**
     * @return list<string>
     */
    private function publishedHiddenPlans(): array
    {
        $publication = $this->publicationRow();
        if ($publication->published_revision_id === null) {
            return [];
        }

        $published = MarketingPlanCatalogRevision::query()->find($publication->published_revision_id);

        return $this->hiddenPlans($published);
    }

    /**
     * @param  array<string, mixed>  $slice
     */
    private function audit(User $actor, string $action, array $slice): void
    {
        WebSettingsAuditLog::query()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'changes' => $slice,
        ]);
    }
}
