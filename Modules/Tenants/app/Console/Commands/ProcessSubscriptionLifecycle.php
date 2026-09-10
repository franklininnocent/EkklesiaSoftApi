<?php

namespace Modules\Tenants\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Tenants\Models\SubscriptionSettings;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Models\TenantSubscriptionAudit;
use Modules\Tenants\Services\SubscriptionLifecycleNotificationPublisher;
use Modules\Tenants\Services\SubscriptionService;

class ProcessSubscriptionLifecycle extends Command
{
    protected $signature = 'tenants:subscription-lifecycle
                            {--dry-run : Log transitions without writing audits}';

    protected $description = 'Record subscription lifecycle transitions (audit/notify side effects only).';

    public function handle(
        SubscriptionService $subscriptionService,
        SubscriptionLifecycleNotificationPublisher $notifier,
    ): int
    {
        $lock = Cache::lock('tenants:subscription-lifecycle', 600);

        if (! $lock->get()) {
            $this->warn('Another lifecycle run is in progress.');

            return self::SUCCESS;
        }

        try {
            $settings = SubscriptionSettings::current();
            $graceDays = max(0, (int) $settings->grace_period_days);
            $lookback = now()->subDays(max($graceDays + 30, 45));

            $processed = 0;

            Tenant::query()
                ->where('active', 1)
                ->where(function ($query) use ($lookback) {
                    $query->whereNotNull('subscription_ends_at')
                        ->where('subscription_ends_at', '>=', $lookback)
                        ->orWhereNotNull('subscription_suspended_at');
                })
                ->orderBy('id')
                ->chunkById(100, function ($tenants) use ($subscriptionService, $notifier, &$processed) {
                    foreach ($tenants as $tenant) {
                        $this->processTenant($subscriptionService, $notifier, $tenant, $processed);
                    }
                });

            $this->info("Processed {$processed} lifecycle transition(s).");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    private function processTenant(
        SubscriptionService $subscriptionService,
        SubscriptionLifecycleNotificationPublisher $notifier,
        Tenant $tenant,
        int &$processed,
    ): void
    {
        $status = $subscriptionService->resolveStatus($tenant);
        $operation = $this->operationForStatus($status);

        if ($operation === null) {
            return;
        }

        if ($this->alreadyRecorded($tenant->id, $operation, $status)) {
            return;
        }

        if ($this->option('dry-run')) {
            $this->line("Would record {$operation} for tenant {$tenant->id} ({$status})");
            $processed++;

            return;
        }

        $subscriptionService->recordLifecycleTransition($tenant, $operation);
        $notifier->notifyTransition($tenant->fresh(), $operation);

        Log::info('Subscription lifecycle transition recorded', [
            'tenant_id' => $tenant->id,
            'operation' => $operation,
            'status' => $status,
        ]);

        $processed++;
    }

    private function operationForStatus(string $status): ?string
    {
        return match ($status) {
            SubscriptionService::STATUS_EXPIRING => 'entered_expiring',
            SubscriptionService::STATUS_GRACE_PERIOD => 'entered_grace',
            SubscriptionService::STATUS_EXPIRED => 'entered_expired',
            default => null,
        };
    }

    private function alreadyRecorded(int $tenantId, string $operation, string $status): bool
    {
        return TenantSubscriptionAudit::query()
            ->where('tenant_id', $tenantId)
            ->where('operation', $operation)
            ->where('source', 'scheduler')
            ->where('after_state->status', $status)
            ->exists();
    }
}
