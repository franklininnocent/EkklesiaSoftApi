<?php

namespace Modules\Tenants\Console\Commands;

use Illuminate\Console\Command;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Support\CountryFiscalYearCatalog;

class SyncCountryFiscalYearCatalogCommand extends Command
{
    protected $signature = 'church:sync-country-fiscal-years';

    protected $description = 'Apply fiscal-year start defaults from CountryFiscalYearCatalog to countries table';

    public function handle(): int
    {
        $updated = 0;
        Country::query()->chunkById(100, function ($countries) use (&$updated): void {
            foreach ($countries as $country) {
                $defaults = CountryFiscalYearCatalog::defaultForIso2($country->iso2);
                $month = str_pad((string) $defaults['month'], 2, '0', STR_PAD_LEFT);
                $day = str_pad((string) $defaults['day'], 2, '0', STR_PAD_LEFT);
                if (
                    (string) $country->fiscal_year_start_month === $month
                    && (string) $country->fiscal_year_start_day === $day
                ) {
                    continue;
                }
                $country->updateQuietly([
                    'fiscal_year_start_month' => $month,
                    'fiscal_year_start_day' => $day,
                ]);
                $updated++;
            }
        });

        $this->info("Updated fiscal year defaults on {$updated} countries.");

        return self::SUCCESS;
    }
}
