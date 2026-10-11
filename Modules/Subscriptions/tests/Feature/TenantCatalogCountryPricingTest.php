<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Subscriptions\Models\PlanCatalogPrice;
use Modules\Marketing\Tests\Support\PublishesMarketingPlanCatalog;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Address;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class TenantCatalogCountryPricingTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use PublishesMarketingPlanCatalog;
    use RefreshDatabase;

    #[Test]
    public function us_tenant_sees_usd_catalog_prices_and_is_assigned_usd(): void
    {
        $country = $this->country('US', 'USD', 'United States');

        $tenant = Tenant::factory()->create();
        $this->attachOfficialAddress($tenant, $country);
        $this->asTenantAdmin($tenant);

        $starter = collect($this->getJson('/api/tenant/subscription/comparison')->json('data.plans'))
            ->firstWhere('code', 'STARTER');

        $this->assertSame('USD', $starter['currency_code']);
        $this->assertSame('39.00', $starter['monthly_price']);
        $this->assertNull($starter['annual_price']);

        $this->publishMarketingPlanCatalog();

        $public = collect($this->getJson('/api/public/subscription-plans')->json('data'))
            ->firstWhere('code', 'STARTER');
        $this->assertSame('INR', $public['currency_code']);
        $this->assertSame('1499.00', $public['monthly_price']);

        $subscription = $this->assignPlan($tenant, 'STARTER');
        $this->assertSame('USD', $subscription->currency_code);
        $this->assertSame('39.00', (string) $subscription->contracted_price);
    }

    #[Test]
    public function brazil_tenant_sees_brl_catalog_prices_and_is_assigned_brl(): void
    {
        $country = $this->country('BR', 'BRL', 'Brazil');

        $tenant = Tenant::factory()->create();
        $this->attachOfficialAddress($tenant, $country);
        $this->asTenantAdmin($tenant);

        $standard = collect($this->getJson('/api/tenant/subscription/comparison')->json('data.plans'))
            ->firstWhere('code', 'STANDARD');

        $this->assertSame('BRL', $standard['currency_code']);
        $this->assertSame('199.00', $standard['monthly_price']);

        $subscription = $this->assignPlan($tenant, 'PROFESSIONAL');
        $this->assertSame('BRL', $subscription->currency_code);
        $this->assertSame('399.00', (string) $subscription->contracted_price);
    }

    #[Test]
    public function tenant_without_us_or_brazil_address_keeps_version_prices(): void
    {
        $ctx = $this->asTenantAdmin();
        $starter = collect($this->getJson('/api/tenant/subscription/comparison')->json('data.plans'))
            ->firstWhere('code', 'STARTER');

        $this->assertSame('INR', $starter['currency_code']);
        $this->assertSame('1499.00', $starter['monthly_price']);
        $this->assertSame('14990.00', $starter['annual_price']);

        $subscription = $this->assignPlan($ctx['tenant'], 'STARTER');
        $this->assertSame('INR', $subscription->currency_code);
        $this->assertSame('1499.00', (string) $subscription->contracted_price);
    }

    #[Test]
    public function existing_catalog_country_prices_are_not_overwritten(): void
    {
        $starter = $this->plan('STARTER');
        $row = PlanCatalogPrice::query()->where('plan_id', $starter->id)->where('country_code', 'US')->first();
        $this->assertNotNull($row);
        $row->update(['monthly_price' => '41.00']);

        app(\Modules\Subscriptions\Services\Catalog\CatalogBootstrapper::class)->run();

        $this->assertSame('41.00', (string) $row->fresh()->monthly_price);
    }

    private function country(string $iso2, string $currency, string $name): Country
    {
        $existing = Country::query()->where('iso2', $iso2)->first();
        if ($existing) {
            return $existing;
        }

        return Country::factory()->create([
            'iso2' => $iso2,
            'currency' => $currency,
            'name' => $name,
            'active' => true,
        ]);
    }

    private function attachOfficialAddress(Tenant $tenant, Country $country): void
    {
        Address::query()->create([
            'addressable_id' => $tenant->id,
            'addressable_type' => Tenant::class,
            'address_type' => 'official',
            'line1' => '1 Parish Rd',
            'district' => 'City',
            'state_province' => 'State',
            'country' => $country->name,
            'country_id' => $country->id,
            'pin_zip_code' => '12345',
            'active' => 1,
            'is_default' => true,
        ]);
    }
}
