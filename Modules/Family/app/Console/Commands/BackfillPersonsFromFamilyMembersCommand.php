<?php

namespace Modules\Family\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\Family\app\Services\PersonBackfillService;

class BackfillPersonsFromFamilyMembersCommand extends Command
{
    protected $signature = 'family:backfill-persons {--tenant= : Optional tenant id}';

    protected $description = 'Create one Person per FamilyMember that is missing person_id (no merging).';

    public function handle(PersonBackfillService $backfill): int
    {
        $tenant = $this->option('tenant');
        $tenantId = $tenant !== null && $tenant !== '' ? (int) $tenant : null;

        $result = $backfill->run($tenantId);

        $this->info(sprintf(
            'Persons created: %d, skipped: %d, sacraments linked: %d',
            $result['created'],
            $result['skipped'],
            $result['linked_sacraments']
        ));

        return self::SUCCESS;
    }
}
