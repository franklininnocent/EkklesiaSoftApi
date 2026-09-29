<?php

namespace Modules\Tenants\Tests\Concerns;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Penetration\CrossTenantPenetrationFixtureSeeder;
use Modules\Tenants\Penetration\CrossTenantVictimFixtures;
use Tests\Concerns\ActsAsTenantRoles;

trait BuildsCrossTenantPenetrationFixtures
{
    use ActsAsTenantRoles;

    protected Tenant $attackerTenant;

    protected Tenant $victimTenant;

    protected User $attacker;

    protected CrossTenantVictimFixtures $victim;

    /**
     * @return list<string>
     */
    protected function penetrationPermissionNames(): array
    {
        return array_values(array_unique(array_merge($this->parishAdminPermissionNames(), [
            'families.view',
            'families.create',
            'families.edit',
            'families.delete',
            'pastoral.care.view',
            'pastoral.care.create',
            'pastoral.care.assign',
            'tenant.data.export',
        ])));
    }

    protected function setUpCrossTenantPenetrationFixtures(): void
    {
        $attackerContext = $this->asTenantAdmin();
        $this->attackerTenant = $attackerContext['tenant'];
        $this->attacker = $attackerContext['user'];
        $this->grantPermissions($attackerContext['role'], $this->penetrationPermissionNames());
        $this->refreshUserPermissionState($this->attacker);

        $this->victimTenant = $this->makeOperationalTenant();
        $this->victim = app(CrossTenantPenetrationFixtureSeeder::class)->seedVictimTenant($this->victimTenant);

        Passport::actingAs($this->attacker->fresh());
    }

    /**
     * @param  list<int>  $allowedStatuses
     */
    protected function assertDeniedCrossTenantAccess(
        string $method,
        string $uri,
        array $allowedStatuses = [404],
        array $payload = [],
    ): void {
        $response = match (strtoupper($method)) {
            'GET' => $this->getJson($uri),
            'POST' => $this->postJson($uri, $payload),
            'PUT' => $this->putJson($uri, $payload),
            'PATCH' => $this->patchJson($uri, $payload),
            'DELETE' => $this->deleteJson($uri, $payload),
            default => throw new \InvalidArgumentException('Unsupported method: '.$method),
        };

        $this->assertContains(
            $response->getStatusCode(),
            $allowedStatuses,
            sprintf('Expected denial for %s %s, got %d', $method, $uri, $response->getStatusCode()),
        );
    }
}
