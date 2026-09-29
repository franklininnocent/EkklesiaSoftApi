<?php

namespace Modules\Tenants\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Facades\Cache;

/**
 * Per-tenant concurrency caps for heavy queues (exports, imports).
 */
final class TenantQueueLimiter
{
    /**
     * @return Lock|false|null Lock acquired, null if busy, false if locks unsupported
     */
    public static function acquireTenantSlot(int $tenantId, string $queue, ?int $maxSlots = null, ?int $seconds = null): Lock|false|null
    {
        if ($tenantId <= 0) {
            return false;
        }

        $maxSlots = max(1, $maxSlots ?? (int) config('tenants.queue.max_concurrent_per_tenant', 1));
        $seconds = max(60, $seconds ?? (int) config('tenants.queue.tenant_lock_seconds', 3600));

        try {
            for ($slot = 0; $slot < $maxSlots; $slot++) {
                $lock = Cache::lock(self::slotKey($tenantId, $queue, $slot), $seconds);
                if ($lock->get()) {
                    return $lock;
                }
            }

            return null;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function releaseOrRequeue(
        Lock|false|null $lock,
        object $job,
        int $releaseSeconds = 30,
    ): bool {
        if ($lock instanceof Lock) {
            return true;
        }

        if ($lock === null && method_exists($job, 'release')) {
            $job->release($releaseSeconds);

            return false;
        }

        return true;
    }

    public static function slotKey(int $tenantId, string $queue, int $slot): string
    {
        return sprintf('tenant-queue:%s:%d:slot:%d', $queue, $tenantId, $slot);
    }
}
