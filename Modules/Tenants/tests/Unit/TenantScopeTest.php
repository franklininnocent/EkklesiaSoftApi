<?php

namespace Modules\Tenants\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Donations\Models\DonationPayment;
use Modules\Tenants\Models\Scopes\TenantScope;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContextBinder;
use Tests\Concerns\InteractsWithTenantContext;
use Tests\TestCase;

class TenantScopeTest extends TestCase
{
    use InteractsWithTenantContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenants.isolation.orm_global_scope' => true]);
    }

    private function makePayment(int $tenantId, string $paymentNumber): DonationPayment
    {
        return DonationPayment::withoutTenantScope()->create([
            'tenant_id' => $tenantId,
            'payment_number' => $paymentNumber,
            'payer_name' => 'Test Payer',
            'amount' => '10.00',
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'payment_date' => now()->toDateString(),
        ]);
    }

    public function test_it_fails_closed_when_no_tenant_context_is_bound(): void
    {
        $tenant = Tenant::factory()->active()->create();

        $this->makePayment($tenant->id, 'PAY-001');

        TenantContextBinder::clear();

        $this->assertSame(0, DonationPayment::query()->count());
    }

    public function test_it_scopes_reads_to_effective_tenant(): void
    {
        $tenantA = Tenant::factory()->active()->create();
        $tenantB = Tenant::factory()->active()->create();

        $this->makePayment($tenantA->id, 'PAY-A-001');
        $this->makePayment($tenantB->id, 'PAY-B-001');

        TenantContextBinder::bind($tenantA->id);

        $this->assertSame(1, DonationPayment::query()->count());
        $this->assertSame('PAY-A-001', DonationPayment::query()->value('payment_number'));
    }

    public function test_run_without_tenant_scope_allows_cross_tenant_reads(): void
    {
        $tenantA = Tenant::factory()->active()->create();
        $tenantB = Tenant::factory()->active()->create();

        $this->makePayment($tenantA->id, 'PAY-A-002');
        $this->makePayment($tenantB->id, 'PAY-B-002');

        TenantContextBinder::bind($tenantA->id);

        $count = DonationPayment::runWithoutTenantScope(
            fn () => DonationPayment::query()->count()
        );

        $this->assertSame(2, $count);
    }

    public function test_scope_can_be_disabled_via_config(): void
    {
        config(['tenants.isolation.orm_global_scope' => false]);

        $tenant = Tenant::factory()->active()->create();

        $this->makePayment($tenant->id, 'PAY-003');

        TenantContextBinder::clear();

        $this->assertSame(1, DonationPayment::query()->count());
    }
}
