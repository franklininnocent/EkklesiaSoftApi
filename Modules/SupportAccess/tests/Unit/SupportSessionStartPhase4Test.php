<?php

namespace Modules\SupportAccess\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\SupportAccess\Models\SupportSessionSetting;
use Modules\SupportAccess\Services\SupportApprovalService;
use Modules\SupportAccess\Services\SupportGrantService;
use Modules\SupportAccess\Services\SupportSessionService;
use Modules\SupportAccess\Services\SupportSettingsService;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SupportSessionStartPhase4Test extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function emergency_without_approval_is_rejected_when_required(): void
    {
        $this->writeOps([
            'emergency_requires_approval' => true,
            'require_customer_grant' => false,
            'jit_enabled' => false,
        ]);

        $tenant = Tenant::factory()->create(['active' => 1]);
        $actor = $this->platformUser();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('approved access request');

        app(SupportSessionService::class)->start($actor, [
            'tenant_id' => $tenant->id,
            'mode' => 'emergency',
            'reason_code' => 'incident',
            'password' => 'secret',
            'confirm_emergency' => true,
        ], '127.0.0.1', 'phpunit');
    }

    #[Test]
    public function grant_required_blocks_start_without_active_grant(): void
    {
        $this->writeOps([
            'emergency_requires_approval' => false,
            'require_customer_grant' => true,
            'jit_enabled' => false,
        ]);

        $tenant = Tenant::factory()->create(['active' => 1]);
        $actor = $this->platformUser();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('customer access grant');

        app(SupportSessionService::class)->start($actor, [
            'tenant_id' => $tenant->id,
            'mode' => 'readonly',
            'reason_code' => 'diagnosis',
            'password' => 'secret',
        ], '127.0.0.1', 'phpunit');
    }

    #[Test]
    public function jit_shortens_standard_session_ttl(): void
    {
        $this->writeOps([
            'timeout_minutes' => 60,
            'emergency_requires_approval' => false,
            'require_customer_grant' => false,
            'jit_enabled' => true,
            'jit_timeout_minutes' => 15,
        ]);

        $tenant = Tenant::factory()->create(['active' => 1]);
        $actor = $this->platformUser();

        $session = app(SupportSessionService::class)->start($actor, [
            'tenant_id' => $tenant->id,
            'mode' => 'standard',
            'reason_code' => 'diagnosis',
            'password' => 'secret',
        ], '127.0.0.1', 'phpunit');

        $diffMinutes = $session->started_at->diffInMinutes($session->expires_at);
        $this->assertEqualsWithDelta(15, $diffMinutes, 1);
    }

    #[Test]
    public function grant_end_clamps_session_expiry(): void
    {
        $this->writeOps([
            'timeout_minutes' => 60,
            'emergency_requires_approval' => false,
            'require_customer_grant' => true,
            'jit_enabled' => false,
        ]);

        $tenant = Tenant::factory()->create(['active' => 1]);
        $actor = $this->platformUser();

        app(SupportGrantService::class)->create($actor, [
            'tenant_id' => $tenant->id,
            'allowed_mode' => 'readonly',
            'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => now()->addMinutes(10)->toIso8601String(),
        ]);

        $session = app(SupportSessionService::class)->start($actor, [
            'tenant_id' => $tenant->id,
            'mode' => 'readonly',
            'reason_code' => 'diagnosis',
            'password' => 'secret',
        ], '127.0.0.1', 'phpunit');

        $diffMinutes = $session->started_at->diffInMinutes($session->expires_at);
        $this->assertLessThanOrEqual(10, $diffMinutes);
        $this->assertNotNull($session->access_grant_id);
    }

    #[Test]
    public function emergency_with_approved_request_starts_and_consumes(): void
    {
        $this->writeOps([
            'timeout_minutes' => 30,
            'emergency_requires_approval' => true,
            'require_customer_grant' => false,
            'jit_enabled' => true,
            'jit_timeout_minutes' => 15,
        ]);

        $tenant = Tenant::factory()->create(['active' => 1]);
        $requester = $this->platformUser();
        $approver = $this->platformUser('approver@example.com');
        $approvals = app(SupportApprovalService::class);

        $request = $approvals->request($requester, [
            'tenant_id' => $tenant->id,
            'mode' => 'emergency',
            'reason_code' => 'incident',
        ]);
        $approvals->approve($approver, $request->id);

        $session = app(SupportSessionService::class)->start($requester, [
            'tenant_id' => $tenant->id,
            'mode' => 'emergency',
            'reason_code' => 'incident',
            'password' => 'secret',
            'confirm_emergency' => true,
            'approval_request_id' => $request->id,
        ], '127.0.0.1', 'phpunit');

        $this->assertSame($request->id, $session->approval_request_id);
        $this->assertSame('consumed', $request->fresh()->status);
        $this->assertEqualsWithDelta(15, $session->started_at->diffInMinutes($session->expires_at), 1);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function writeOps(array $overrides): void
    {
        $defaults = app(SupportSettingsService::class)->defaults();
        unset($defaults['allowed_timeouts'], $defaults['notification_modes']);

        SupportSessionSetting::query()->updateOrCreate(
            ['key' => SupportSettingsService::KEY_OPS],
            ['value' => array_merge($defaults, $overrides)]
        );
    }

    private function platformUser(string $email = 'support@example.com'): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => Role::SUPER_ADMIN],
            [
                'description' => 'Super Admin',
                'level' => 1,
                'active' => 1,
                'tenant_id' => null,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        /** @var User $user */
        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('secret'),
            'role_id' => $role->id,
            'tenant_id' => null,
        ]);

        if (method_exists($user, 'syncRoles')) {
            $user->syncRoles([$role->id]);
        }

        return $user->fresh(['role']);
    }
}
