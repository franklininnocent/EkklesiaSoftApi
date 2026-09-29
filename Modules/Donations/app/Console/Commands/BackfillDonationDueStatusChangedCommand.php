<?php

namespace Modules\Donations\Console\Commands;

use Illuminate\Console\Command;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\DonationAuditLog;
use Modules\Donations\Models\ProjectInstallmentDue;

class BackfillDonationDueStatusChangedCommand extends Command
{
    protected $signature = 'donations:backfill-due-status-changed {--tenant= : Limit to one tenant id}';

    protected $description = 'Backfill status_changed_at on dues from donation audit logs';

    public function handle(): int
    {
        $tenantFilter = $this->option('tenant');
        $updated = 0;

        $contributionEvents = ['due.waived', 'due.cancelled'];
        DonationAuditLog::query()
            ->when($tenantFilter, fn ($q) => $q->where('tenant_id', (int) $tenantFilter))
            ->where('target_type', 'due')
            ->whereIn('event', $contributionEvents)
            ->orderBy('id')
            ->chunkById(200, function ($logs) use (&$updated): void {
                foreach ($logs as $log) {
                    $count = ContributionDue::query()
                        ->where('id', $log->target_id)
                        ->whereNull('status_changed_at')
                        ->update(['status_changed_at' => $log->created_at]);
                    $updated += $count;
                }
            });

        $installmentEvents = ['project_installment.waived', 'project_installment.cancelled'];
        DonationAuditLog::query()
            ->when($tenantFilter, fn ($q) => $q->where('tenant_id', (int) $tenantFilter))
            ->where('target_type', 'project_installment')
            ->whereIn('event', $installmentEvents)
            ->orderBy('id')
            ->chunkById(200, function ($logs) use (&$updated): void {
                foreach ($logs as $log) {
                    $count = ProjectInstallmentDue::query()
                        ->where('id', $log->target_id)
                        ->whereNull('status_changed_at')
                        ->update(['status_changed_at' => $log->created_at]);
                    $updated += $count;
                }
            });

        $this->info("Updated status_changed_at on {$updated} due row(s).");

        return self::SUCCESS;
    }
}
