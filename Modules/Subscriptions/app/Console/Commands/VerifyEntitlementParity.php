<?php

namespace Modules\Subscriptions\Console\Commands;

use Illuminate\Console\Command;
use Modules\Subscriptions\Services\Catalog\EntitlementParityChecker;

class VerifyEntitlementParity extends Command
{
    protected $signature = 'subscriptions:verify-entitlement-parity {--tenant= : Check a single tenant id}';

    protected $description = 'Compare legacy feature decisions with plan-driven entitlements (must be zero mismatches before enforce mode)';

    public function handle(EntitlementParityChecker $checker): int
    {
        $tenantId = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
        $report = $checker->check($tenantId);

        $this->info("Tenants checked: {$report['tenants_checked']}");

        if ($report['tenants_without_subscription'] !== []) {
            $this->warn('Tenants without a CURRENT subscription (run subscriptions:backfill): '.implode(', ', $report['tenants_without_subscription']));
        }

        if ($report['mismatches'] === []) {
            $this->info('Parity OK: no mismatches.');

            return $report['tenants_without_subscription'] === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['Tenant', 'Legacy key', 'Feature', 'Legacy', 'Plan'],
            array_map(static fn (array $m) => [
                $m['tenant_id'],
                $m['legacy_key'],
                $m['feature_code'],
                $m['legacy'] ? 'yes' : 'no',
                $m['plan'] ? 'yes' : 'no',
            ], $report['mismatches'])
        );
        $this->error(count($report['mismatches']).' mismatches found.');

        return self::FAILURE;
    }
}
