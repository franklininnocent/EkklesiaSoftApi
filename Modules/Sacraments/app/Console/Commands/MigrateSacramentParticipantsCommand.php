<?php

namespace Modules\Sacraments\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sacraments\Services\Migration\SacramentMigrationReportService;
use Modules\Sacraments\Services\Migration\SacramentParticipantBackfillService;

class MigrateSacramentParticipantsCommand extends Command
{
    protected $signature = 'sacraments:migrate-participants
        {--tenant-id= : Limit to one tenant}
        {--dry-run : Preview without writing}
        {--chunk=200 : Chunk size}';

    protected $description = 'Idempotent unresolved-first backfill of sacrament participants from legacy denorm names (ADR-11).';

    public function handle(
        SacramentParticipantBackfillService $backfill,
        SacramentMigrationReportService $reportService
    ): int {
        $tenantId = $this->option('tenant-id');
        $tenantId = ($tenantId !== null && $tenantId !== '') ? (int) $tenantId : null;
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));

        $this->info($dryRun
            ? 'Dry run: no data will be modified.'
            : 'Running sacrament participant migration backfill…');

        $totals = $backfill->run($tenantId, $dryRun, $chunk);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed sacraments', $totals['processed']],
                ['Auto-linked (exact name+DOB)', $totals['linked']],
                ['Unresolved slots', $totals['unresolved']],
                ['Skipped (already resolved)', $totals['skipped']],
                ['Participants created', $totals['created_participants']],
                ['Failed sacraments', $totals['failed']],
            ]
        );

        $report = $reportService->report($tenantId);
        $this->newLine();
        $this->info('Migration report (counts only, no PII):');
        $this->table(
            ['Metric', 'Count'],
            collect($report)->map(fn ($v, $k) => [$k, is_bool($v) ? ($v ? 'true' : 'false') : $v])->values()->all()
        );

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
