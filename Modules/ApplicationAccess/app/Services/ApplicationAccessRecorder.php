<?php

namespace Modules\ApplicationAccess\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\ApplicationAccess\Models\ApplicationAccessEvent;
use Modules\ApplicationAccess\Models\ApplicationSecurityEvent;
use Modules\ApplicationAccess\Support\ApplicationMetadataSanitizer;

class ApplicationAccessRecorder
{
    public function __construct(
        private readonly ApplicationMetadataSanitizer $metadataSanitizer,
        private readonly ApplicationSecuritySignalService $signals,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function recordAccessEvent(array $attributes): ?ApplicationAccessEvent
    {
        if (! $this->telemetryEnabled()) {
            return null;
        }

        $eventId = (string) ($attributes['id'] ?? Str::uuid());
        $attributes['id'] = $eventId;

        if (isset($attributes['metadata']) && is_array($attributes['metadata'])) {
            $attributes['metadata'] = $this->metadataSanitizer->sanitize($attributes['metadata']);
        }

        if (ApplicationAccessEvent::query()->whereKey($eventId)->exists()) {
            return ApplicationAccessEvent::query()->find($eventId);
        }

        return ApplicationAccessEvent::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function recordSecurityEvent(array $attributes): ?ApplicationSecurityEvent
    {
        if (! $this->telemetryEnabled()) {
            return null;
        }

        $eventId = (string) ($attributes['id'] ?? Str::uuid());
        $attributes['id'] = $eventId;

        if (isset($attributes['metadata']) && is_array($attributes['metadata'])) {
            $attributes['metadata'] = $this->metadataSanitizer->sanitize($attributes['metadata']);
        }

        if (ApplicationSecurityEvent::query()->whereKey($eventId)->exists()) {
            return ApplicationSecurityEvent::query()->find($eventId);
        }

        return ApplicationSecurityEvent::query()->create($attributes);
    }

    public function recordInvalidTokenSignal(?string $sourceIp): void
    {
        if (! $this->telemetryEnabled()) {
            return;
        }

        $throttleSeconds = 60;
        $cacheKey = 'application_access:invalid_token:'.$sourceIp;

        if ($sourceIp === null || $sourceIp === '' || Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, true, $throttleSeconds);
        $this->signals->recordInvalidToken($sourceIp);
    }

    /**
     * Idempotent ingest for queue retries.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function ingestAccessEventIdempotent(array $attributes): void
    {
        DB::transaction(function () use ($attributes): void {
            $this->recordAccessEvent($attributes);
        });
    }

    private function telemetryEnabled(): bool
    {
        return (bool) config('applicationaccess.telemetry_enabled', false);
    }
}
