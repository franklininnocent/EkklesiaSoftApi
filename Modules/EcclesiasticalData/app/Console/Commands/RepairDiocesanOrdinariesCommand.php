<?php

namespace Modules\EcclesiasticalData\Console\Commands;

use Illuminate\Console\Command;
use Modules\EcclesiasticalData\Services\DiocesanOrdinaryRepairService;

class RepairDiocesanOrdinariesCommand extends Command
{
    protected $signature = 'leadership:repair-diocesan-ordinaries {--dry-run : Report actions without writing}';

    protected $description = 'Ensure one current diocesan ordinary per diocese and sync church profile bishop_id values';

    public function handle(DiocesanOrdinaryRepairService $repairService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run — no database changes will be made.');
        }

        $stats = $repairService->repair($dryRun);

        $this->info(sprintf(
            'Processed %d diocese(s); ended %d duplicate ordinary appointment(s); synced %d church profile(s).',
            $stats['dioceses_processed'],
            $stats['ordinaries_ended'],
            $stats['profiles_synced'],
        ));

        return self::SUCCESS;
    }
}
