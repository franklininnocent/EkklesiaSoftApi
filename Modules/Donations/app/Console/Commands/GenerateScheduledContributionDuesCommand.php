<?php

namespace Modules\Donations\Console\Commands;

use Illuminate\Console\Command;
use Modules\Donations\Services\ContributionDueService;

class GenerateScheduledContributionDuesCommand extends Command
{
    protected $signature = 'donations:generate-scheduled-dues {--tenant= : Limit to a specific tenant ID}';

    protected $description = 'Generate mandatory contribution dues for all active auto-generate plans';

    public function handle(ContributionDueService $dueService): int
    {
        $tenantOption = $this->option('tenant');

        if ($tenantOption) {
            $result = $dueService->generateScheduledDuesForTenant((int) $tenantOption, 0);
        } else {
            $result = $dueService->generateScheduledDuesForAllTenants();
        }

        $this->info(sprintf(
            'Processed %d plan(s), generated %d due(s).',
            $result['processed'],
            $result['generated']
        ));

        return self::SUCCESS;
    }
}
