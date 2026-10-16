<?php

namespace Modules\ApplicationAccess\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\ApplicationAccess\Models\ApplicationAccessEvent;
use Modules\ApplicationAccess\Models\ApplicationAccessSession;
use Modules\ApplicationAccess\Support\ApplicationAccessStatsKeys;

class ApplicationAccessStatsService
{
    public function __construct(
        private readonly ApplicationAccessStreamConnectionManager $connections,
    ) {}

    /**
     * @return array<string, int|bool>
     */
    public function snapshot(): array
    {
        $since = now()->subDay();

        return [
            'telemetry_enabled' => (bool) config('applicationaccess.telemetry_enabled', false),
            'ingested_access_events_24h' => ApplicationAccessEvent::query()
                ->where('occurred_at', '>=', $since)
                ->count(),
            'capture_failures' => (int) Cache::get(ApplicationAccessStatsKeys::CAPTURE_FAILURES, 0),
            'sse_connections' => count($this->connections->connections()),
            'geo_failed_sessions' => ApplicationAccessSession::query()
                ->where('geo_status', 'LOOKUP_FAILED')
                ->count(),
            'geo_jobs_failed' => $this->countFailedGeoJobs(),
        ];
    }

    private function countFailedGeoJobs(): int
    {
        if (! $this->failedJobsTableExists()) {
            return 0;
        }

        return (int) DB::table('failed_jobs')
            ->where('payload', 'like', '%ApplicationAccess%')
            ->where('payload', 'like', '%Geo%')
            ->count();
    }

    private function failedJobsTableExists(): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable('failed_jobs');
        } catch (\Throwable) {
            return false;
        }
    }
}
