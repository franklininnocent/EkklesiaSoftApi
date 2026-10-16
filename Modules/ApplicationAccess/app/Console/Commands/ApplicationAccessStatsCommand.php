<?php

namespace Modules\ApplicationAccess\Console\Commands;

use Illuminate\Console\Command;
use Modules\ApplicationAccess\Services\ApplicationAccessStatsService;

class ApplicationAccessStatsCommand extends Command
{
    protected $signature = 'application-access:stats';

    protected $description = 'Show application access telemetry health counters';

    public function handle(ApplicationAccessStatsService $stats): int
    {
        $snapshot = $stats->snapshot();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Telemetry enabled', $snapshot['telemetry_enabled'] ? 'yes' : 'no'],
                ['Access events ingested (24h)', number_format((int) $snapshot['ingested_access_events_24h'])],
                ['Capture failures (lifetime)', number_format((int) $snapshot['capture_failures'])],
                ['Active SSE connections', number_format((int) $snapshot['sse_connections'])],
                ['Sessions with geo lookup failed', number_format((int) $snapshot['geo_failed_sessions'])],
                ['Failed geo jobs', number_format((int) $snapshot['geo_jobs_failed'])],
            ],
        );

        return self::SUCCESS;
    }
}
