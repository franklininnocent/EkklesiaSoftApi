<?php

namespace Modules\Subscriptions\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Support\SubscriptionCatalogDefinition;
use Modules\Subscriptions\Services\SubscriptionPolicyService;
use Modules\Tenants\Models\SubscriptionSettings;

class ApplyScheduledBillingMaintenance extends Command
{
    protected $signature = 'billing:apply-scheduled-maintenance';

    protected $description = 'End online checkout maintenance when maintenance_until has passed';

    public function handle(SubscriptionPolicyService $policies): int
    {
        $changed = 0;

        DB::transaction(function () use (&$changed, $policies): void {
            $locked = SubscriptionSettings::query()->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            $raw = is_array($locked->policies) ? $locked->policies : json_decode((string) $locked->policies, true);
            if (! is_array($raw)) {
                $raw = SubscriptionCatalogDefinition::defaultPolicies();
            }

            $billing = (array) ($raw['billing'] ?? []);
            if (($billing['online_checkout'] ?? '') !== 'maintenance') {
                return;
            }

            $until = $billing['maintenance_until'] ?? null;
            if ($until === null || $until === '') {
                return;
            }

            $endsAt = \Illuminate\Support\Carbon::parse($until);
            if ($endsAt->isFuture()) {
                return;
            }

            $revert = (string) ($billing['maintenance_revert_to'] ?? 'enabled');
            if (! in_array($revert, ['enabled', 'disabled'], true)) {
                $revert = 'enabled';
            }

            $billing['online_checkout'] = $revert;
            $billing['maintenance_until'] = null;
            $billing['policy_version'] = (int) ($billing['policy_version'] ?? 1) + 1;
            $raw['billing'] = $billing;
            $locked->forceFill(['policies' => $raw])->save();
            $policies->forgetPoliciesMemo();
            $changed = 1;
        });

        $this->info($changed ? 'Checkout maintenance window ended; online_checkout reverted.' : 'No maintenance window to apply.');

        return self::SUCCESS;
    }
}
