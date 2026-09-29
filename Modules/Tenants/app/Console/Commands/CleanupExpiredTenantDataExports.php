<?php

namespace Modules\Tenants\Console\Commands;

use Illuminate\Console\Command;
use Modules\Tenants\Export\TenantExportStorage;
use Modules\Tenants\Models\TenantDataExport;
use Modules\Tenants\Services\TenantDataExportAuditService;

class CleanupExpiredTenantDataExports extends Command
{
    protected $signature = 'tenants:cleanup-exports
                            {--dry-run : Show what would be cleaned without deleting}';

    protected $description = 'Expire completed tenant data exports past retention and delete their archive files';

    public function handle(TenantDataExportAuditService $auditService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $storage = new TenantExportStorage;
        $expiredCount = 0;
        $failedTempCount = 0;

        $due = TenantDataExport::query()
            ->where('status', TenantDataExport::STATUS_COMPLETED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit(500)
            ->get();

        foreach ($due as $export) {
            $this->line("Expire export {$export->id} (tenant {$export->tenant_id})");
            if ($dryRun) {
                $expiredCount++;

                continue;
            }

            if ($export->file_path) {
                $storage->deleteRelative((string) $export->file_path);
            }

            $export->status = TenantDataExport::STATUS_EXPIRED;
            $export->file_path = null;
            $export->save();

            $auditService->logExportEvent(
                (int) $export->tenant_id,
                (string) $export->id,
                TenantDataExportAuditService::EVENT_EXPIRED
            );
            $expiredCount++;
        }

        $hours = max(1, (int) config('tenants.export.failed_temp_retention_hours', 24));
        $failed = TenantDataExport::query()
            ->where('status', TenantDataExport::STATUS_FAILED)
            ->where('updated_at', '<=', now()->subHours($hours))
            ->orderBy('updated_at')
            ->limit(200)
            ->get();

        foreach ($failed as $export) {
            $workRelative = sprintf('exports/%d/%s/work', $export->tenant_id, $export->id);
            if ($storage->disk()->exists($workRelative)) {
                $this->line("Cleanup failed work dir for {$export->id}");
                if (! $dryRun) {
                    $storage->disk()->deleteDirectory($workRelative);
                }
                $failedTempCount++;
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Expired exports: {$expiredCount}; failed temp dirs: {$failedTempCount}");

        return self::SUCCESS;
    }
}
