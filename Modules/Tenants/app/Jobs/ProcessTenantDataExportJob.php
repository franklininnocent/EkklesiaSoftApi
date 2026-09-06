<?php

namespace Modules\Tenants\Jobs;

use Illuminate\Support\Facades\Cache;
use Modules\Tenants\Models\TenantDataExport;
use Modules\Tenants\Services\TenantDataExportOrchestrator;
use Modules\Tenants\Support\TenantQueueLimiter;

class ProcessTenantDataExportJob extends TenantAwareJob
{
    public int $tries = 3;

    public int $timeout;

    public function __construct(
        int $tenantId,
        ?int $actorUserId,
        private readonly string $exportId,
    ) {
        parent::__construct($tenantId, $actorUserId);

        $this->timeout = max(60, (int) config('tenants.export.job_timeout', 1800));
        $this->onQueue((string) config('tenants.export.queue', 'tenant-exports'));
    }

    protected function handleWithTenantContext(): void
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

        $tenantLock = TenantQueueLimiter::acquireTenantSlot(
            $this->tenantId,
            (string) config('tenants.export.queue', 'tenant-exports'),
        );

        if (! TenantQueueLimiter::releaseOrRequeue(
            $tenantLock,
            $this,
            (int) config('tenants.queue.tenant_release_seconds', 30),
        )) {
            return;
        }

        $maxGlobal = max(1, (int) config('tenants.export.max_concurrent_global', 2));
        $globalLock = $this->acquireGlobalSlot($maxGlobal, (int) $this->timeout + 60);

        if ($globalLock === false) {
            app(TenantDataExportOrchestrator::class)->process($this->exportId);

            return;
        }

        if ($globalLock === null) {
            $this->release(30);

            return;
        }

        try {
            app(TenantDataExportOrchestrator::class)->process($this->exportId);
        } finally {
            if ($tenantLock instanceof \Illuminate\Contracts\Cache\Lock) {
                $tenantLock->release();
            }
            if ($globalLock instanceof \Illuminate\Contracts\Cache\Lock) {
                $globalLock->release();
            }
        }
    }

    /**
     * @return \Illuminate\Contracts\Cache\Lock|false|null
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
