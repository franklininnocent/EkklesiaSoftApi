<?php

namespace Modules\MassIntentions\Console\Commands;

use Illuminate\Console\Command;
use Modules\MassIntentions\Services\MassIntentionOfficeCloseService;

class CloseExpiredMassIntentionsCommand extends Command
{
    protected $signature = 'mass-intentions:close-expired';

    protected $description = 'Close open Mass intentions whose scheduled date has passed (per church timezone).';

    public function handle(MassIntentionOfficeCloseService $closer): int
    {
        $count = $closer->closeExpiredForAllTenants();
        $this->info("Closed {$count} expired Mass intention(s).");

        return self::SUCCESS;
    }
}
