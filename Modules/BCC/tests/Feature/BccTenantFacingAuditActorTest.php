<?php

namespace Modules\BCC\Tests\Feature;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\BCC\Models\BccAuditLog;
use Modules\BCC\Services\BccAuditService;
use Modules\BCC\Testing\BccCertificationTestCase;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\SupportAccess\Models\SupportSession;
use Modules\Tenants\Support\ActiveSupportSession;
use Modules\Tenants\Support\SupportSessionMode;
use Modules\Tenants\Support\TenantContext;
use Modules\Tenants\Support\TenantFacingAuditActor;
use PHPUnit\Framework\Attributes\Test;

class BccTenantFacingAuditActorTest extends BccCertificationTestCase
{
    #[Test]
    public function tenant_user_create_exposes_real_actor_name(): void
    {
        $ctx = $this->actingAsTenantWith(['bcc.view', 'bcc.create']);
        $ctx['user']->update(['name' => 'Parish Secretary']);

        $bccId = $this->postJson('/api/bccs', $this->validBccPayload(['name' => 'Parish BCC']))
            ->assertCreated()
            ->json('data.id');

        $response = $this->getJson("/api/bccs/{$bccId}/audit-logs");
        $response->assertOk();

        $created = collect($response->json('data'))->firstWhere('event', 'bcc.created');
        $this->assertNotNull($created);
        $this->assertSame('Parish Secretary', $created['actor_name']);
        $this->assertSame((int) $ctx['user']->id, (int) $created['actor_user_id']);
        $this->assertNull(BccAuditLog::query()->find($created['id'])?->support_session_id);
    }

    #[Test]
    public function support_session_create_update_delete_expose_ekklesia_support_and_keep_operator_id(): void
    {
        [$operator, $tenant, $session, $viewer] = $this->seedStandardSupportSession();
        Passport::actingAs($operator);

        $bccId = $this->withHeader('X-Support-Session-Id', $session->id)
            ->postJson('/api/bccs', $this->validBccPayload([
                'name' => 'Support BCC',
                'isSupport' => true,
                'actor_name' => 'Ekklesia Support',
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->withHeader('X-Support-Session-Id', $session->id)
            ->putJson("/api/bccs/{$bccId}", $this->validBccPayload(['name' => 'Support BCC Updated']))
            ->assertOk();

        // BCC update does not write an audit row; stamp while Support context is still bound.
        $this->bindOperatorSupportContext($operator, $session);
        app(BccAuditService::class)->log(
            (int) $tenant->id,
            'bcc.updated',
            'bcc',
            (string) $bccId,
            ['name' => 'Support BCC'],
            ['name' => 'Support BCC Updated'],
            $bccId,
        );

        $this->withHeader('X-Support-Session-Id', $session->id)
            ->deleteJson("/api/bccs/{$bccId}")
            ->assertOk();

        $session->update([
            'status' => SupportSession::STATUS_ENDED,
            'ended_at' => now(),
        ]);

        $this->flushHeaders();
        $viewer->clearPermissionsCache();
        User::flushRequestPermissionCache();
        Passport::actingAs($viewer->fresh(['roles']));

        $logs = $this->getJson('/api/bccs/audit-logs')->assertOk()->json('data');
        $byEvent = collect($logs)->keyBy('event');

        $created = $byEvent->get('bcc.created');
        $this->assertNotNull($created);
        $this->assertSame(TenantFacingAuditActor::DISPLAY_NAME, $created['actor_name']);
        $this->assertSame((int) $operator->id, (int) $created['actor_user_id']);
        $this->assertSame($session->id, BccAuditLog::query()->find($created['id'])?->support_session_id);

        $updated = $byEvent->get('bcc.updated');
        $this->assertNotNull($updated);
        $this->assertSame(TenantFacingAuditActor::DISPLAY_NAME, $updated['actor_name']);
        $this->assertSame((int) $operator->id, (int) $updated['actor_user_id']);

        $deleted = $byEvent->get('bcc.deleted');
        $this->assertNotNull($deleted);
        $this->assertSame(TenantFacingAuditActor::DISPLAY_NAME, $deleted['actor_name']);
        $this->assertSame((int) $operator->id, (int) $deleted['actor_user_id']);
    }

    #[Test]
    public function client_is_support_payload_cannot_force_masking_for_tenant_user(): void
    {
        $ctx = $this->actingAsTenantWith(['bcc.view', 'bcc.create']);
        $ctx['user']->update(['name' => 'Parish Secretary']);

        $bccId = $this->postJson('/api/bccs', $this->validBccPayload([
            'name' => 'Spoofed BCC',
            'isSupport' => true,
            'actor_name' => 'Ekklesia Support',
            'support_session_id' => (string) Str::uuid(),
        ]))->assertCreated()->json('data.id');

        $row = BccAuditLog::query()->where('target_id', $bccId)->where('event', 'bcc.created')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->support_session_id);
        $this->assertSame((int) $ctx['user']->id, (int) $row->actor_user_id);

        $created = collect($this->getJson("/api/bccs/{$bccId}/audit-logs")->json('data'))
            ->firstWhere('event', 'bcc.created');
        $this->assertSame('Parish Secretary', $created['actor_name']);
    }

    /**
     * @return array{0: User, 1: \Modules\Tenants\Models\Tenant, 2: SupportSession, 3: User}
     */
    private function seedStandardSupportSession(): array
    {
        $ctx = $this->makeTenantUser(['bcc.view']);
        $tenant = $ctx['tenant'];
        $parishUser = $ctx['user'];

        $role = Role::create([
            'name' => 'SupportAdmin',
            'description' => 'Support operator',
            'level' => Role::LEVEL_EKKLESIA_MANAGER,
            'active' => 1,
            'tenant_id' => null,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_PLATFORM,
        ]);

        $permissionIds = [];
        foreach (['support.sessions.start', 'support.sessions.standard', 'support.sessions.view'] as $name) {
            $permissionIds[] = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => $name,
                    'module' => 'SupportAccess',
                    'scope' => Permission::SCOPE_PLATFORM,
                    'category' => 'support',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            )->id;
        }
        $role->permissions()->sync($permissionIds);

        $operator = User::factory()->create([
            'name' => 'Franklin Innocent F',
            'tenant_id' => null,
            'role_id' => $role->id,
            'password' => Hash::make('secret'),
        ]);
        $operator->syncRoles([$role->id]);
        $operator->clearPermissionsCache();
        User::flushRequestPermissionCache();

        $session = SupportSession::query()->create([
            'id' => (string) Str::uuid(),
            'support_user_id' => $operator->id,
            'tenant_id' => $tenant->id,
            'mode' => 'standard',
            'reason_code' => 'diagnosis',
            'status' => SupportSession::STATUS_ACTIVE,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);

        return [$operator->fresh(['role', 'roles']), $tenant, $session, $parishUser->fresh(['roles'])];
    }

    private function bindOperatorSupportContext(User $operator, SupportSession $session): void
    {
        $expiresAt = $session->expires_at?->toDateTimeImmutable()
            ?? new \DateTimeImmutable('+30 minutes');

        app()->instance(TenantContext::class, TenantContext::fromUserAndSession(
            $operator,
            new ActiveSupportSession(
                id: (string) $session->id,
                tenantId: (int) $session->tenant_id,
                mode: SupportSessionMode::Standard,
                supportUserId: (int) $operator->id,
                expiresAt: $expiresAt,
                reasonCode: (string) $session->reason_code,
            ),
        ));
    }
}
