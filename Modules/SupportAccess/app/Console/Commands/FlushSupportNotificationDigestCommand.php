<?php

namespace Modules\SupportAccess\Console\Commands;

use Illuminate\Console\Command;
use Modules\SupportAccess\Services\SupportSessionNotificationPublisher;

class FlushSupportNotificationDigestCommand extends Command
{
    protected $signature = 'support:flush-notification-digest';

    protected $description = 'Send queued Support Access digest notifications';

    public function handle(SupportSessionNotificationPublisher $publisher): int
    {
        $count = $publisher->flushDigest();
        $this->info("Flushed {$count} digest notification(s).");

        return self::SUCCESS;
    }
}
