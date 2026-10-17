<?php

namespace Modules\Marketing\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Marketing\Services\MarketingRegionalPricesAssembler;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanCatalogPrice;
use Modules\Subscriptions\Models\PlanVersion;
use Tests\TestCase;

class MarketingRegionalPricesAssemblerTest extends TestCase
{
    use RefreshDatabase;

    public function test_assembles_inr_from_version_and_usd_brl_from_catalog(): void
    {
        $starter = Plan::query()->where('code', 'STARTER')->firstOrFail();
        $version = $starter->activeVersion;
        $this->assertNotNull($version);

        $result = app(MarketingRegionalPricesAssembler::class)->assemble($starter, $version);

        $this->assertSame([], $result['errors']);
        $this->assertSame('1499.00', $result['regional_prices']['INR']['monthly_price']);
        $this->assertSame('14990.00', $result['regional_prices']['INR']['annual_price']);
        $this->assertSame('39.00', $result['regional_prices']['USD']['monthly_price']);
        $this->assertNull($result['regional_prices']['USD']['annual_price']);
        $this->assertSame('99.00', $result['regional_prices']['BRL']['monthly_price']);
    }

    public function test_inr_catalog_row_disagreeing_with_version_is_error(): void
    {
        $starter = Plan::query()->where('code', 'STARTER')->firstOrFail();
        $version = $starter->activeVersion;
        $this->assertNotNull($version);

        PlanCatalogPrice::query()->create([
            'plan_id' => $starter->id,
            'country_code' => 'IN',
            'currency_code' => 'INR',
            'monthly_price' => '1599.00',
            'annual_price' => '14990.00',
        ]);

        $result = app(MarketingRegionalPricesAssembler::class)->assemble($starter, $version);

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('plan_catalog_prices IN monthly', $result['errors'][0]);
    }

    public function test_usd_uses_us_catalog_row_not_other_usd_countries(): void
    {
        $starter = Plan::query()->where('code', 'STARTER')->firstOrFail();
        $version = $starter->activeVersion;
        $this->assertNotNull($version);

        PlanCatalogPrice::query()->create([
            'plan_id' => $starter->id,
            'country_code' => 'CA',
            'currency_code' => 'USD',
            'monthly_price' => '49.00',
            'annual_price' => null,
        ]);

        $result = app(MarketingRegionalPricesAssembler::class)->assemble($starter, $version);

        $this->assertSame([], $result['errors']);
        $this->assertSame('39.00', $result['regional_prices']['USD']['monthly_price']);
    }

    public function test_zero_price_is_preserved(): void
    {
        $starter = Plan::query()->where('code', 'STARTER')->firstOrFail();
        $version = $starter->activeVersion;
        $this->assertNotNull($version);

        PlanCatalogPrice::query()
            ->where('plan_id', $starter->id)
            ->where('country_code', 'US')
            ->update(['monthly_price' => '0.00']);

        $result = app(MarketingRegionalPricesAssembler::class)->assemble($starter, $version);

        $this->assertSame([], $result['errors']);
        $this->assertSame('0.00', $result['regional_prices']['USD']['monthly_price']);
    }
}
