<?php

namespace Modules\Tenants\Console\Commands;

use Illuminate\Console\Command;
use Modules\Tenants\Models\TenantStatusAudit;

/**
 * Artisan Command to manually cleanup old tenant status audit logs.
 * 
 * This command is useful for:
 * - Initial cleanup of existing data
 * - Scheduled maintenance via cron
 * - Manual cleanup when needed
 * 
 * Usage:
 *   php artisan tenants:cleanup-audit
 *   php artisan tenants:cleanup-audit --limit=50
 */
class CleanupAuditLogs extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'tenants:cleanup-audit
                            {--limit=25 : Number of records to keep per tenant}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Cleanup old tenant status audit logs, keeping only the most recent records per tenant';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');
        
        $this->info("🧹 Tenant Status Audit Cleanup");
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("Retention Policy: Keep last {$limit} records per tenant");
        
        if ($dryRun) {
            $this->warn("⚠️  DRY RUN MODE - No records will be deleted");
        }
        
        $this->newLine();
        
        try {
            // Get current statistics
            $this->info("📊 Current Statistics:");
            $stats = TenantStatusAudit::getRecordCountsByTenant();
            $totalRecords = $stats->sum('record_count');
            $totalTenants = $stats->count();
            
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Tenants with Audit Logs', number_format($totalTenants)],
                    ['Total Audit Records', number_format($totalRecords)],
                    ['Average per Tenant', number_format($totalRecords / max($totalTenants, 1), 2)],
                ]
            );
            
            $this->newLine();
            
            // Show tenants with > limit records
            $tenantsToClean = $stats->filter(fn($t) => $t->record_count > $limit);
            
            if ($tenantsToClean->isEmpty()) {
                $this->info("✅ All tenants are within the retention limit!");
                return self::SUCCESS;
            }
            
            $this->warn("⚠️  Tenants exceeding retention limit:");
            $this->table(
                ['Tenant ID', 'Current Records', 'Will Keep', 'Will Delete'],
                $tenantsToClean->map(function ($tenant) use ($limit) {
                    $willDelete = $tenant->record_count - $limit;
                    return [
                        $tenant->tenant_id,
                        number_format($tenant->record_count),
                        number_format($limit),
                        number_format($willDelete),
                    ];
                })->toArray()
            );
            
            $this->newLine();
            
            if ($dryRun) {
                $this->info("✅ Dry run complete - no changes made");
                return self::SUCCESS;
            }
            
            // Confirm before proceeding
            if (!$this->confirm("Do you want to proceed with cleanup?", true)) {
                $this->warn("⚠️  Cleanup cancelled");
                return self::SUCCESS;
            }
            
            $this->newLine();
            $this->info("🔄 Running cleanup...");
            
            // Run cleanup
            $bar = $this->output->createProgressBar($tenantsToClean->count());
            $bar->start();
            
            $cleanupStats = TenantStatusAudit::cleanupAllTenants($limit);
            
            $bar->finish();
            $this->newLine(2);
            
            // Show results
            if ($cleanupStats['success']) {
                $this->info("✅ Cleanup completed successfully!");
                $this->newLine();
                
                $this->table(
                    ['Metric', 'Value'],
                    [
                        ['Tenants Processed', number_format($cleanupStats['tenants_processed'])],
                        ['Records Deleted', number_format($cleanupStats['records_deleted'])],
                        ['Duration', number_format($cleanupStats['duration_seconds'], 2) . 's'],
                    ]
                );
                
                // Show new statistics
                $newStats = TenantStatusAudit::getRecordCountsByTenant();
                $newTotal = $newStats->sum('record_count');
                
                $this->newLine();
                $this->info("📊 New Statistics:");
                $this->table(
                    ['Metric', 'Value'],
                    [
                        ['Total Audit Records', number_format($newTotal)],
                        ['Records Freed', number_format($totalRecords - $newTotal)],
                        ['Average per Tenant', number_format($newTotal / max($newStats->count(), 1), 2)],
                    ]
                );
                
                return self::SUCCESS;
            } else {
                $this->error("❌ Cleanup failed: " . ($cleanupStats['error'] ?? 'Unknown error'));
                return self::FAILURE;
            }
            
        } catch (\Exception $e) {
            $this->error("❌ Error: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return self::FAILURE;
        }
    }
}

