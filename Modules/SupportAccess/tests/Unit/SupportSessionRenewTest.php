<?php

namespace Modules\SupportAccess\Tests\Unit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Authentication\Models\User;
use Modules\SupportAccess\Models\SupportSession;
use Modules\SupportAccess\Services\SupportSessionService;
use Modules\SupportAccess\Services\SupportSettingsService;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupportSessionRenewTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function renew_extends_active_session_with_password(): void
    {
        $tenant = Tenant::factory()->create(['active' => 1]);
        $hash = Hash::make('Secret123!');
        $user = User::factory()->create([
            'password' => $hash,
        ]);
        $actor = $this->platformActor((int) $user->id, $hash);

        app(SupportSettingsService::class)->updateOpsSettings([
            'timeout_minutes' => 30,
            'jit_enabled' => false,
            'emergency_requires_approval' => false,
            'require_customer_grant' => false,
        ]);

        $service = app(SupportSessionService::class);
        $session = $service->start($actor, [
            'tenant_id' => $tenant->id,
            'mode' => 'readonly',
            'reason_code' => 'diagnosis',
            'password' => 'Secret123!',
        ], '127.0.0.1', 'phpunit');

        $before = $session->expires_at->copy();
        $this->travel(5)->minutes();

        $renewed = $service->renew($actor, $session->id, ['password' => 'Secret123!'], '127.0.0.1');

        $this->assertTrue($renewed->expires_at->gt($before));
        $this->assertDatabaseHas('support_session_events', [
            'support_session_id' => $session->id,
            'event_type' => 'session_renewed',
        ]);
    }

    private function platformActor(int $id, string $passwordHash): Authenticatable
    {
        return new class($id, $passwordHash) implements Authenticatable
        {
            public string $password;

            public function __construct(private readonly int $id, string $passwordHash)
            {
                $this->password = $passwordHash;
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
                return $this->password;
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

            public function hasPermission(string $permission): bool
            {
                return true;
            }
        };
    }
}
