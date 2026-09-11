<?php

namespace Modules\ApplicationAccess\Console\Commands;

use Illuminate\Console\Command;
use Modules\ApplicationAccess\Services\ApplicationAccessRetentionService;

class ApplicationAccessRetentionCommand extends Command
{
    protected $signature = 'application-access:retention
                            {--dry-run : Report rows that would be removed without deleting}';

    protected $description = 'Prune aged application access telemetry and expire due IP block rules';

    public function handle(ApplicationAccessRetentionService $retention): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run — no rows will be deleted.');
        }

        $counts = $retention->run($dryRun);

        $this->table(
            ['Dataset', $dryRun ? 'Would prune' : 'Pruned'],
            [
                ['Access events', number_format($counts['access_events'])],
                ['Security events', number_format($counts['security_events'])],
                ['Signals', number_format($counts['signals'])],
                ['Ended sessions', number_format($counts['sessions'])],
                ['Expired IP blocks', number_format($counts['ip_blocks_expired'])],
            ],
        );

        if (! $dryRun) {
            $this->info('Retention run recorded as a privileged security event.');
        }

        return self::SUCCESS;
    }
}
