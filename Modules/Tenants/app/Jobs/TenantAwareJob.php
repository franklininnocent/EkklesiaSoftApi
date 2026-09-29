<?php

namespace Modules\Tenants\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Tenants\Support\SubscriptionJobWriteGuard;
use Modules\Tenants\Support\TenantContextBinder;

/**
 * Queue jobs that require a trusted TenantContext for the duration of handle().
 */
abstract class TenantAwareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly ?int $actorUserId = null,
    ) {
    }

    final public function handle(): void
    {
        if ($this->tenantId <= 0) {
            return;
        }

        if (! $this->allowsTenantMutations()) {
            return;
        }

        TenantContextBinder::bind($this->tenantId, $this->actorUserId);

        try {
            $this->handleWithTenantContext();
        } finally {
            TenantContextBinder::clear();
        }
    }

    abstract protected function handleWithTenantContext(): void;

    protected function allowsTenantMutations(): bool
    {
        return app(SubscriptionJobWriteGuard::class)->allowsMutationsForTenantId($this->tenantId);
    }
}
