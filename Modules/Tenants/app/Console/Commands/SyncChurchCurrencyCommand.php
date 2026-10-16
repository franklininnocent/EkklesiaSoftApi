<?php

namespace Modules\Tenants\Console\Commands;

use Illuminate\Console\Command;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\ChurchCurrencyResolver;

class SyncChurchCurrencyCommand extends Command
{
    protected $signature = 'tenants:sync-church-currency {--tenant= : Optional tenant id}';

    protected $description = 'Backfill tenants.currency_code and donation_settings.default_currency from official address country';

    public function handle(ChurchCurrencyResolver $resolver): int
    {
        $tenantId = $this->option('tenant');

        $query = Tenant::query()->orderBy('id');
        if ($tenantId !== null && $tenantId !== '') {
            $query->whereKey((int) $tenantId);
        }

        $total = (clone $query)->count();
        $updated = 0;

        foreach ($query->cursor() as $tenant) {
            $resolver->syncDerivedColumns((int) $tenant->id);
            $updated++;

            if ($this->output->isVerbose()) {
                $currency = $resolver->currencyCodeForTenantId((int) $tenant->id) ?? '(unresolved)';
                $this->line("  Tenant {$tenant->id} ({$tenant->name}): {$currency}");
            }
        }

        $this->info("Synced church currency for {$updated} tenant(s).");

        if ($total !== $updated) {
            $this->warn("Expected {$total} tenant(s) in scope; processed {$updated}.");
        }

        return self::SUCCESS;
    }
}
