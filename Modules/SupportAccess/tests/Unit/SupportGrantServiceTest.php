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

    #[Test]
    public function equal_start_and_end_is_rejected(): void
    {
        $tenant = Tenant::factory()->create(['active' => 1]);
        $user = User::factory()->create();
        $actor = $this->platformActor((int) $user->id);
        $service = app(SupportGrantService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Grant end time must be after start time.');
        $service->create($actor, [
            'tenant_id' => $tenant->id,
            'allowed_mode' => 'any',
            'starts_at' => '2026-09-10T10:00',
            'ends_at' => '2026-09-10T10:00',
        ]);
    }

    #[Test]
    public function datetime_local_format_is_accepted(): void
    {
        $tenant = Tenant::factory()->create(['active' => 1]);
        $user = User::factory()->create();
        $actor = $this->platformActor((int) $user->id);
        $service = app(SupportGrantService::class);

        $grant = $service->create($actor, [
            'tenant_id' => $tenant->id,
            'allowed_mode' => 'readonly',
            'starts_at' => now()->subMinute()->format('Y-m-d\TH:i'),
            'ends_at' => now()->addHour()->format('Y-m-d\TH:i'),
        ]);

        $this->assertSame(SupportAccessGrant::STATUS_ACTIVE, $grant->status);
    }

    #[Test]
    public function list_filters_by_search_status_and_allowed_mode(): void
    {
        $tenantA = Tenant::factory()->create(['active' => 1, 'name' => 'Alpha Parish', 'slug' => 'alpha']);
        $tenantB = Tenant::factory()->create(['active' => 1, 'name' => 'Beta Parish', 'slug' => 'beta']);
        $grantor = User::factory()->create(['name' => 'Grant Manager', 'email' => 'grants@ekklesia.test']);
        $actor = $this->platformActor((int) $grantor->id);
        $service = app(SupportGrantService::class);

        $service->create($actor, [
            'tenant_id' => $tenantA->id,
            'allowed_mode' => 'standard',
            'starts_at' => now()->subHour()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
        ]);
        $revoked = $service->create($actor, [
            'tenant_id' => $tenantB->id,
            'allowed_mode' => 'emergency',
            'starts_at' => now()->subHour()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
        ]);
        $service->revoke($actor, $revoked->id);

        $byTenant = $service->list(['q' => 'alpha'], 50);
        $this->assertCount(1, $byTenant->items());
        $this->assertSame((int) $tenantA->id, (int) $byTenant->items()[0]->tenant_id);

        $byGrantor = $service->list(['q' => 'grants@ekklesia'], 50);
        $this->assertCount(2, $byGrantor->items());

        $byMode = $service->list(['allowed_mode' => 'emergency'], 50);
        $this->assertCount(1, $byMode->items());
        $this->assertSame('emergency', $byMode->items()[0]->allowed_mode);

        $byStatus = $service->list(['status' => SupportAccessGrant::STATUS_REVOKED], 50);
        $this->assertCount(1, $byStatus->items());
        $this->assertSame(SupportAccessGrant::STATUS_REVOKED, $byStatus->items()[0]->status);
    }

    private function platformActor(int $id): Authenticatable
    {
        return new class($id) implements Authenticatable
        {
            public function __construct(private readonly int $id) {}

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

            public function setRememberToken($value): void {}

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
