<?php

namespace Modules\SupportAccess\Tests\Unit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\SupportAccess\Services\SupportGrantService;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ParishSupportGrantServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function create_for_own_tenant_forces_tenant_id(): void
    {
        $tenant = Tenant::factory()->create(['active' => 1]);
        $other = Tenant::factory()->create(['active' => 1]);
        $user = User::factory()->create();
        $actor = $this->platformActor((int) $user->id);

        $grant = app(SupportGrantService::class)->createForOwnTenant($actor, (int) $tenant->id, [
            'tenant_id' => $other->id, // must be ignored
            'allowed_mode' => 'readonly',
            'starts_at' => now()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
        ]);

        $this->assertSame((int) $tenant->id, (int) $grant->tenant_id);
    }

    #[Test]
    public function revoke_for_other_tenant_is_rejected(): void
    {
        $tenantA = Tenant::factory()->create(['active' => 1]);
        $tenantB = Tenant::factory()->create(['active' => 1]);
        $user = User::factory()->create();
        $actor = $this->platformActor((int) $user->id);
        $service = app(SupportGrantService::class);

        $grant = $service->createForOwnTenant($actor, (int) $tenantA->id, [
            'allowed_mode' => 'any',
            'starts_at' => now()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not belong');
        $service->revokeForOwnTenant($actor, (int) $tenantB->id, $grant->id);
    }

    private function platformActor(int $id): Authenticatable
    {
        return new class($id) implements Authenticatable
        {
            public function __construct(private readonly int $id)
            {
            }

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier(): mixed
            {
                return $this->id;
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getAuthPassword(): string
            {
                return '';
            }

            public function getRememberToken(): ?string
            {
                return null;
            }

            public function setRememberToken($value): void
            {
            }

            public function getRememberTokenName(): string
            {
                return 'remember_token';
            }

            public function isSuperAdmin(): bool
            {
                return true;
            }
        };
    }
}
