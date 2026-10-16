<?php

namespace Modules\Tenants\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\Tenants\Database\Seeders\SubscriptionEnforcementE2eSeeder;
use Modules\Tenants\Models\SubscriptionSettings;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionService;

class SubscriptionEnforcementSmoke extends Command
{
    protected $signature = 'tenants:subscription-enforcement-smoke
                            {--require-enforcement : Fail when view-only/locked enforcement flag is off}';

    protected $description = 'Pre-flight checks before enabling TENANT_SUBSCRIPTION_VIEW_ONLY_LOCKED_ENFORCEMENT on staging/production.';

    public function handle(SubscriptionService $subscriptionService): int
    {
        $ok = true;

        $this->info('Subscription view-only / locked — enforcement smoke');
        $this->newLine();

        if (! Schema::hasColumn('subscription_settings', 'view_only_allowance_days')) {
            $this->error('Missing column subscription_settings.view_only_allowance_days — run Tenants migrations.');
            $ok = false;
        }

        if (! Schema::hasColumn('tenants', 'custom_view_only_allowance_days')) {
            $this->error('Missing tenant override columns — run Tenants migrations.');
            $ok = false;
        }

        $settings = SubscriptionSettings::current();
        $this->line('Global grace_period_days: '.(int) $settings->grace_period_days);
        $this->line('Global view_only_allowance_days: '.(int) ($settings->view_only_allowance_days ?? 0));

        $enforcement = $subscriptionService->usesViewOnlyLockedEnforcement();
        $this->line('TENANT_SUBSCRIPTION_VIEW_ONLY_LOCKED_ENFORCEMENT: '.($enforcement ? 'true' : 'false'));

        if ($this->option('require-enforcement') && ! $enforcement) {
            $this->error('--require-enforcement set but flag is false.');
            $ok = false;
        }

        $this->newLine();
        $this->info('E2E fixture parishes (seed with SubscriptionEnforcementE2eSeeder):');

        foreach ([
            SubscriptionEnforcementE2eSeeder::VIEW_ONLY_SLUG => SubscriptionService::ACCESS_MODE_VIEW_ONLY,
            SubscriptionEnforcementE2eSeeder::LOCKED_SLUG => SubscriptionService::ACCESS_MODE_LOCKED,
        ] as $slug => $expectedMode) {
            $tenant = Tenant::query()->where('slug', $slug)->first();
            if (! $tenant) {
                $this->warn("  [missing] slug={$slug} — run db:seed for SubscriptionEnforcementE2eSeeder");
                continue;
            }

            $access = $subscriptionService->evaluateAccess($tenant);
            $mode = (string) $access['access_mode'];
            $match = $enforcement && $mode === $expectedMode;
            $label = $match ? 'OK' : ($enforcement ? 'UNEXPECTED' : 'skipped (enforcement off)');

            $this->line(sprintf(
                '  [%s] #%d %s — access_mode=%s (expect %s when enforcement on)',
                $label,
                $tenant->id,
                $tenant->name,
                $mode,
                $expectedMode
            ));

            if ($enforcement && $mode !== $expectedMode) {
                $ok = false;
            }
        }

        $this->newLine();
        if ($ok) {
            $this->info('Smoke checks passed.');

            return self::SUCCESS;
        }

        $this->error('Smoke checks failed.');

        return self::FAILURE;
    }
}
