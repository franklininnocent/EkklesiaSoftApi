<?php

namespace Modules\Tenants\Tests\Unit;

use Modules\Donations\Models\DonationSetting;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Tenants\Models\Address;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\ChurchFinancialPeriodResolver;
use Tests\TestCase;

class ChurchFinancialPeriodResolverTest extends TestCase
{
    public function test_india_style_fiscal_year_label_and_bounds_on_september_reference(): void
    {
        $country = Country::factory()->create([
            'iso2' => 'IN',
            'fiscal_year_start_month' => '04',
            'fiscal_year_start_day' => '01',
        ]);

        $tenant = Tenant::factory()->create();

        Address::query()->create([
            'addressable_id' => $tenant->id,
            'addressable_type' => Tenant::class,
            'address_type' => 'official',
            'line1' => '1 Church Rd',
            'district' => 'City',
            'state_province' => 'State',
            'country' => $country->name,
            'country_id' => $country->id,
            'pin_zip_code' => '560001',
            'active' => 1,
            'is_default' => true,
        ]);

        DonationSetting::create([
            'tenant_id' => $tenant->id,
            'financial_year_source' => 'country',
            'financial_year_start_month' => '01',
            'financial_year_start_day' => '01',
            'receipt_prefix' => 'RCPT',
            'created_by' => 1,
            'updated_by' => 1,
        ]);

        $resolver = app(ChurchFinancialPeriodResolver::class);
        $resolver->syncDerivedFyStartColumns((int) $tenant->id);

        $reference = '2026-09-28';
        $period = $resolver->currentFiscalYear((int) $tenant->id, $reference);

        $this->assertSame('2026-04-01', $period->start);
        $this->assertSame('2027-03-31', $period->end);
        $this->assertSame('FY 2026–27', $period->label);
        $this->assertSame('2026', $period->key);

        $bounds = DonationBusinessDate::currentFinancialYearBounds((int) $tenant->id, $reference);
        $this->assertSame($period->start, $bounds['start']);
        $this->assertSame($period->end, $bounds['end']);
    }

    public function test_calendar_year_label_when_start_is_january(): void
    {
        $country = Country::factory()->create([
            'iso2' => 'US',
            'fiscal_year_start_month' => '01',
            'fiscal_year_start_day' => '01',
        ]);

        $tenant = Tenant::factory()->create();
        Address::query()->create([
            'addressable_id' => $tenant->id,
            'addressable_type' => Tenant::class,
            'address_type' => 'official',
            'line1' => '1 Main St',
            'district' => 'City',
            'state_province' => 'NY',
            'country' => $country->name,
            'country_id' => $country->id,
            'pin_zip_code' => '10001',
            'active' => 1,
            'is_default' => true,
        ]);

        DonationSetting::create([
            'tenant_id' => $tenant->id,
            'financial_year_source' => 'country',
            'financial_year_start_month' => '01',
            'financial_year_start_day' => '01',
            'receipt_prefix' => 'RCPT',
            'created_by' => 1,
            'updated_by' => 1,
        ]);

        $period = app(ChurchFinancialPeriodResolver::class)->currentFiscalYear((int) $tenant->id, '2026-09-28');

        $this->assertSame('2026-01-01', $period->start);
        $this->assertSame('2026-12-31', $period->end);
        $this->assertSame('FY 2026', $period->label);
    }
}
