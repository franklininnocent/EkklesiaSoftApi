<?php

namespace Modules\Tenants\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Modules\Tenants\Models\TenantDataExport;
use Modules\Tenants\Services\TenantDataExportOrchestrator;

class ProcessTenantDataExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout;

    public function __construct(private readonly string $exportId)
    {
        $this->timeout = max(60, (int) config('tenants.export.job_timeout', 1800));
        $this->onQueue((string) config('tenants.export.queue', 'tenant-exports'));
    }

    public function handle(TenantDataExportOrchestrator $orchestrator): void
    {
        $export = TenantDataExport::find($this->exportId);
        if (! $export) {
            return;
        }

        if (in_array($export->status, [
            TenantDataExport::STATUS_CANCELLED,
            TenantDataExport::STATUS_COMPLETED,
            TenantDataExport::STATUS_EXPIRED,
        ], true)) {
            return;
        }

        $maxGlobal = max(1, (int) config('tenants.export.max_concurrent_global', 2));
        $lock = $this->acquireGlobalSlot($maxGlobal, (int) $this->timeout + 60);

        if ($lock === false) {
            // No lock backend available (e.g. array cache in tests) — proceed without global cap.
            $orchestrator->process($this->exportId);

            return;
        }

        if ($lock === null) {
            $this->release(30);

            return;
        }

        try {
            $orchestrator->process($this->exportId);
        } finally {
            $lock->release();
        }
    }

    /**
     * Acquire one of N global export slots via cache locks.
     *
     * @return Lock|false|null Lock, null if busy, false if locks unsupported
     */
    private function acquireGlobalSlot(int $maxSlots, int $seconds)
    {
        try {
            for ($slot = 0; $slot < $maxSlots; $slot++) {
                $lock = Cache::lock('tenant-data-export:global:'.$slot, $seconds);
                if ($lock->get()) {
                    return $lock;
                }
            }

            return null;
        } catch (\Throwable) {
            return false;
        }
    }
}
