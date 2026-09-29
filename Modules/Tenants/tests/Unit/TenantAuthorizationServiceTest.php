<?php

namespace Modules\Tenants\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Donations\Models\DonationPayment;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\TenantAuthorizationService;
use Modules\Tenants\Support\TenantContextBinder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class TenantAuthorizationServiceTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_it_authorizes_resource_in_effective_tenant(): void
    {
        $tenant = Tenant::factory()->active()->create();
        TenantContextBinder::bind($tenant->id);

        $payment = $this->makePayment($tenant->id, 'PAY-AUTH-001');

        $service = app(TenantAuthorizationService::class);

        $service->authorizeResource($payment);

        $this->assertTrue($service->resourceBelongsToEffectiveTenant($payment));
    }

    public function test_it_rejects_cross_tenant_resource_with_not_found(): void
    {
        $tenantA = Tenant::factory()->active()->create();
        $tenantB = Tenant::factory()->active()->create();
        TenantContextBinder::bind($tenantA->id);

        $foreignPayment = $this->makePayment($tenantB->id, 'PAY-AUTH-002');

        $service = app(TenantAuthorizationService::class);

        $this->expectException(NotFoundHttpException::class);
        $service->authorizeResource($foreignPayment);
    }

    public function test_actor_authorization_uses_effective_tenant_for_support_sessions(): void
    {
        $homeTenant = Tenant::factory()->active()->create();
        $targetTenant = Tenant::factory()->active()->create();
        $supportUser = User::factory()->create(['tenant_id' => null]);

        TenantContextBinder::bind($targetTenant->id, (int) $supportUser->id, null);

        $payment = $this->makePayment($targetTenant->id, 'PAY-AUTH-003');

        $service = app(TenantAuthorizationService::class);

        $service->authorizeActorCanAccessResource($supportUser, $payment);

        $this->assertTrue(true);
    }
}
