<?php

namespace Modules\SupportAccess\Console\Commands;

use Illuminate\Console\Command;
use Modules\SupportAccess\Services\SupportSessionService;

class ExpireSupportSessionsCommand extends Command
{
    protected $signature = 'support:expire-sessions';

    protected $description = 'Expire overdue active support sessions';

    public function handle(SupportSessionService $sessions): int
    {
        $count = $sessions->expireStaleSessions();
        $this->info("Expired {$count} support session(s).");

        return self::SUCCESS;
    }
}
