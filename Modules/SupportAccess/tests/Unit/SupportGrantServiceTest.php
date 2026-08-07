<?php

namespace Modules\SupportAccess\Tests\Unit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\SupportAccess\Models\SupportAccessGrant;
use Modules\SupportAccess\Services\SupportGrantService;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\SupportSessionMode;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SupportGrantServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function create_and_find_active_covering_grant(): void
    {
        $tenant = Tenant::factory()->create(['active' => 1]);
        $user = User::factory()->create();
        $actor = $this->platformActor((int) $user->id);
        $service = app(SupportGrantService::class);

        $grant = $service->create($actor, [
            'tenant_id' => $tenant->id,
            'allowed_mode' => 'standard',
            'starts_at' => now()->subHour()->toIso8601String(),
            'ends_at' => now()->addHours(2)->toIso8601String(),
            'max_sessions' => 2,
        ]);

        $this->assertSame(SupportAccessGrant::STATUS_ACTIVE, $grant->status);

        $found = $service->findActiveCovering((int) $tenant->id, SupportSessionMode::Standard);
        $this->assertNotNull($found);
        $this->assertSame($grant->id, $found->id);

        $missing = $service->findActiveCovering((int) $tenant->id, SupportSessionMode::Emergency);
        $this->assertNull($missing);
    }

    #[Test]
    public function any_mode_covers_emergency(): void
    {
        $tenant = Tenant::factory()->create(['active' => 1]);
        $user = User::factory()->create();
        $actor = $this->platformActor((int) $user->id);
        $service = app(SupportGrantService::class);

        $service->create($actor, [
            'tenant_id' => $tenant->id,
            'allowed_mode' => 'any',
            'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
        ]);

        $found = $service->findActiveCovering((int) $tenant->id, SupportSessionMode::Emergency);
        $this->assertNotNull($found);
    }

    #[Test]
    public function revoke_prevents_active_match(): void
    {
        $tenant = Tenant::factory()->create(['active' => 1]);
        $user = User::factory()->create();
        $actor = $this->platformActor((int) $user->id);
        $service = app(SupportGrantService::class);

        $grant = $service->create($actor, [
            'tenant_id' => $tenant->id,
            'allowed_mode' => 'readonly',
            'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
        ]);

        $service->revoke($actor, $grant->id);
        $this->assertNull($service->findActiveCovering((int) $tenant->id, SupportSessionMode::Readonly));
    }

    #[Test]
    public function invalid_window_is_rejected(): void
    {
        $tenant = Tenant::factory()->create(['active' => 1]);
        $user = User::factory()->create();
        $actor = $this->platformActor((int) $user->id);
        $service = app(SupportGrantService::class);

        $this->expectException(RuntimeException::class);
        $service->create($actor, [
            'tenant_id' => $tenant->id,
            'allowed_mode' => 'any',
            'starts_at' => now()->addHour()->toIso8601String(),
            'ends_at' => now()->toIso8601String(),
        ]);
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
