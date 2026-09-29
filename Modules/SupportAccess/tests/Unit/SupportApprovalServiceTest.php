<?php

namespace Modules\SupportAccess\Tests\Unit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\SupportAccess\Models\SupportAccessRequest;
use Modules\SupportAccess\Services\SupportApprovalService;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\SupportSessionMode;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SupportApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function four_eyes_blocks_self_approve(): void
    {
        $tenant = Tenant::factory()->create(['active' => 1]);
        $user = User::factory()->create();
        $actor = $this->platformActor((int) $user->id);
        $service = app(SupportApprovalService::class);

        $request = $service->request($actor, [
            'tenant_id' => $tenant->id,
            'mode' => 'emergency',
            'reason_code' => 'incident',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Four-eyes');

        $service->approve($actor, $request->id);
    }

    #[Test]
    public function approver_can_approve_and_claim_for_start(): void
    {
        $tenant = Tenant::factory()->create(['active' => 1]);
        $requesterUser = User::factory()->create();
        $approverUser = User::factory()->create();
        $requester = $this->platformActor((int) $requesterUser->id);
        $approver = $this->platformActor((int) $approverUser->id);
        $service = app(SupportApprovalService::class);

        $request = $service->request($requester, [
            'tenant_id' => $tenant->id,
            'mode' => 'emergency',
            'reason_code' => 'incident',
        ]);

        $approved = $service->approve($approver, $request->id, 'ok');
        $this->assertSame(SupportAccessRequest::STATUS_APPROVED, $approved->status);

        $claimed = $service->claimForSessionStart(
            $requester,
            $approved->id,
            (int) $tenant->id,
            SupportSessionMode::Emergency,
        );
        $this->assertSame($approved->id, $claimed->id);

        $service->markConsumed($claimed, '11111111-1111-1111-1111-111111111111');
        $this->assertSame(SupportAccessRequest::STATUS_CONSUMED, $claimed->fresh()->status);
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
