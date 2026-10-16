<?php

namespace Modules\Tenants\Tests\Unit;

use Modules\Tenants\Models\Address;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\ChurchCurrencyResolver;
use Tests\TestCase;

class ChurchCurrencyResolverTest extends TestCase
{
    public function test_it_resolves_currency_from_official_address_country(): void
    {
        $country = Country::query()->where('iso2', 'US')->first();
        if ($country === null || ! $country->currency) {
            $this->markTestSkipped('US country seed required.');
        }

        $tenant = Tenant::factory()->create(['currency_code' => 'INR']);
        Address::query()->create([
            'addressable_id' => $tenant->id,
            'addressable_type' => Tenant::class,
            'address_type' => 'official',
            'line1' => '1 Main St',
            'district' => 'City',
            'state_province' => 'State',
            'country' => $country->name,
            'country_id' => $country->id,
            'pin_zip_code' => '12345',
            'active' => 1,
            'is_default' => true,
        ]);

        $resolver = app(ChurchCurrencyResolver::class);
        $dto = $resolver->forTenantId((int) $tenant->id);

        $this->assertNotNull($dto);
        $this->assertSame('USD', $dto->currencyCodeOrNull());
        $this->assertSame('US', $dto->countryCode);
    }

    public function test_it_memoizes_per_request(): void
    {
        $resolver = app(ChurchCurrencyResolver::class);
        $tenant = Tenant::factory()->create();

        $first = $resolver->forTenantId((int) $tenant->id);
        $second = $resolver->forTenantId((int) $tenant->id);

        $this->assertSame($first, $second);
    }
}
