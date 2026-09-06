<?php

namespace Modules\RolesAndPermissions\Tests\Feature;

use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCC;
use Modules\Donations\Models\DonationPayment;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentType;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

/**
 * Cross-module SaaS RBAC + tenant isolation matrix.
 *
 * Family/Members use identity checks (Administrator / person_id).
 * Sacraments, Donations, and BCC use tenant.permission middleware.
 */
class SaasRbac360FeatureTest extends TestCase
{
    use ActsAsTenantRoles;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $adminA;

    private User $staffA;

    private User $adminB;

    private User $superAdmin;

    private User $deactivatedAdmin;

    private Role $adminARole;

    private Role $staffARole;

    private Family $familyA;

    private Family $familyAOther;

    private Family $familyB;

    private FamilyMember $memberA;

    private FamilyMember $memberB;

    private Person $personA;

    private Person $personB;

    private Sacrament $sacramentA;

    private Sacrament $sacramentB;

    private SacramentType $baptismType;

    private BCC $bccA;

    private BCC $bccB;

    private DonationPayment $paymentA;

    private DonationPayment $paymentB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = $this->makeOperationalTenant();
        $this->tenantB = $this->makeOperationalTenant();

        $adminA = $this->makeTenantPersona(
            $this->tenantA,
            Role::TENANT_ADMINISTRATOR,
            $this->parishAdminPermissionNames(),
            [
                'is_custom' => false,
                'level' => 1,
                'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
            ]
        );
        $staffA = $this->makeTenantPersona(
            $this->tenantA,
            'Staff',
            $this->staffPermissionNames(),
            [
                'is_custom' => true,
                'level' => 3,
                'role_classification' => Role::CLASSIFICATION_CUSTOM,
            ]
        );
        $adminB = $this->makeTenantPersona(
            $this->tenantB,
            Role::TENANT_ADMINISTRATOR,
            $this->parishAdminPermissionNames(),
            [
                'is_custom' => false,
                'level' => 1,
                'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
            ]
        );

        $this->adminA = $adminA['user'];
        $this->adminARole = $adminA['role'];
        $this->staffA = $staffA['user'];
        $this->staffARole = $staffA['role'];
        $this->adminB = $adminB['user'];

        $super = $this->asSuperAdmin();
        $this->superAdmin = $super['user'];

        $deactivated = $this->asDeactivatedUser($this->tenantA);
        $this->deactivatedAdmin = $deactivated['user'];

        $this->baptismType = SacramentType::factory()->create([
            'name' => 'Baptism',
            'code' => 'BAPTISM-RBAC360',
            'active' => true,
            'requires_minister' => false,
        ]);

        $this->seedTenantRecords();
        $this->clearApiAuth();
    }

    #[Test]
    #[DataProvider('unauthenticatedEndpoints')]
    public function unauthenticated_requests_are_rejected(string $method, string $uri): void
    {
        $this->clearApiAuth();
        $this->json($method, $uri, $method === 'POST' ? ['name' => 'x'] : [])
            ->assertUnauthorized();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unauthenticatedEndpoints(): array
    {
        return [
            'families index' => ['GET', '/api/families'],
            'families store' => ['POST', '/api/families'],
            'members index' => ['GET', '/api/members'],
            'persons index' => ['GET', '/api/persons'],
            'sacraments index' => ['GET', '/api/sacraments'],
            'sacraments store' => ['POST', '/api/sacraments'],
            'donations payments index' => ['GET', '/api/tenant/donations/payments'],
            'donations payments store' => ['POST', '/api/tenant/donations/payments'],
            'bccs index' => ['GET', '/api/bccs'],
            'bccs store' => ['POST', '/api/bccs'],
            'tenant roles' => ['GET', '/api/tenant/roles'],
        ];
    }

    #[Test]
    public function tenant_admin_cannot_access_or_mutate_other_tenant_resources(): void
    {
        $this->switchTo($this->adminA);
        $this->assertCrossTenantDenied();
    }

    #[Test]
    public function staff_cannot_access_or_mutate_other_tenant_resources(): void
    {
        $this->switchTo($this->staffA);
        $this->assertCrossTenantDenied();
    }

    #[Test]
    public function list_and_search_never_expose_other_tenant_records(): void
    {
        $this->switchTo($this->staffA);

        $familyIds = $this->pluckIds($this->getJson('/api/families?per_page=50')->assertOk(), 'data');
        $this->assertTrue($familyIds->contains($this->familyA->id));
        $this->assertFalse($familyIds->contains($this->familyB->id));

        $memberIds = $this->pluckIds($this->getJson('/api/members?search=BravoRbac&per_page=50')->assertOk(), 'data');
        $this->assertFalse($memberIds->contains($this->memberB->id));

        $alphaMembers = $this->pluckIds($this->getJson('/api/members?search=AlphaRbac&per_page=50')->assertOk(), 'data');
        $this->assertTrue($alphaMembers->contains($this->memberA->id));

        $personSearch = $this->pluckIds($this->getJson('/api/persons?search=BravoRbac&per_page=20')->assertOk(), 'data');
        $this->assertFalse($personSearch->contains($this->personB->id));

        $matches = $this->postJson('/api/persons/matches', [
            'first_name' => 'BravoRbac',
            'last_name' => 'Member',
            'date_of_birth' => $this->memberB->date_of_birth?->format('Y-m-d') ?? $this->memberB->date_of_birth,
        ])->assertOk();
        $this->assertFalse($this->pluckIds($matches, 'data')->contains($this->personB->id));

        $sacramentIds = $this->pluckIds($this->getJson('/api/sacraments?search=BravoRbac&per_page=50')->assertOk(), 'data.data');
        $this->assertFalse($sacramentIds->contains($this->sacramentB->id));

        $lookup = $this->getJson('/api/bccs/families/lookup?search=BravoRbac')->assertOk();
        $lookupIds = collect($lookup->json('data') ?? $lookup->json())->pluck('id');
        $this->assertFalse($lookupIds->contains($this->familyB->id));

        $bccIds = $this->pluckIds($this->getJson('/api/bccs?search=BravoRbac')->assertOk(), 'data');
        $this->assertFalse($bccIds->contains($this->bccB->id));

        $financial = $this->getJson('/api/tenant/donations/search?q=BravoRbac')->assertOk()->json('data');
        $financialBlob = json_encode($financial);
        $this->assertStringNotContainsString((string) $this->familyB->id, (string) $financialBlob);
        $this->assertStringNotContainsString('BravoRbacFamily', (string) $financialBlob);

        $paymentIds = $this->pluckIds(
            $this->getJson('/api/tenant/donations/payments?per_page=50')->assertOk(),
            'data.data'
        );
        $this->assertTrue($paymentIds->contains($this->paymentA->id));
        $this->assertFalse($paymentIds->contains($this->paymentB->id));
    }

    #[Test]
    public function parish_admin_has_full_crud_including_deletes_in_own_tenant(): void
    {
        $this->switchTo($this->adminA);

        $familyId = $this->postJson('/api/families', [
            'family_name' => 'Admin Created Household',
            'status' => 'active',
        ])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('families', [
            'id' => $familyId,
            'tenant_id' => $this->tenantA->id,
            'family_name' => 'Admin Created Household',
        ]);

        $memberId = $this->postJson("/api/families/{$familyId}/members", $this->memberPayload('Admin', 'Child'))
            ->assertCreated()
            ->json('data.id');
        $this->assertDatabaseHas('family_members', ['id' => $memberId, 'first_name' => 'Admin']);

        $sacramentId = $this->postJson('/api/sacraments', $this->baptismPayload('Admin Recipient'))
            ->assertCreated()
            ->json('data.id');
        $this->assertDatabaseHas('sacraments', [
            'id' => $sacramentId,
            'tenant_id' => $this->tenantA->id,
            'recipient_name' => 'Admin Recipient',
        ]);

        $paymentId = $this->postJson(
            '/api/tenant/donations/payments',
            $this->paymentPayload($this->familyA, '25.00')
        )->assertCreated()->json('data.id');
        $this->assertDatabaseHas('donation_payments', [
            'id' => $paymentId,
            'tenant_id' => $this->tenantA->id,
        ]);

        $bccId = $this->postJson('/api/bccs', $this->bccPayload(['name' => 'Admin Created BCC']))
            ->assertCreated()
            ->json('data.id');
        $this->assertDatabaseHas('bccs', [
            'id' => $bccId,
            'tenant_id' => $this->tenantA->id,
            'name' => 'Admin Created BCC',
        ]);

        $this->deleteJson("/api/families/{$familyId}?force_delete=1")->assertOk();
        $this->assertSoftDeleted('families', ['id' => $familyId]);

        $this->deleteJson("/api/bccs/{$bccId}")->assertOk();
        $this->assertSoftDeleted('bccs', ['id' => $bccId]);

        $this->deleteJson("/api/sacraments/{$sacramentId}")->assertOk();
        $this->assertSoftDeleted('sacraments', ['id' => $sacramentId]);

        $this->getJson('/api/tenant/sacrament-settings')->assertOk();
        $this->patchJson("/api/tenant/sacrament-settings/{$this->baptismType->id}", [
            'is_active' => true,
        ])->assertOk();
    }

    #[Test]
    public function staff_can_create_and_update_but_cannot_administratively_delete(): void
    {
        $this->switchTo($this->staffA);

        $familyId = $this->postJson('/api/families', [
            'family_name' => 'Staff Household',
            'status' => 'active',
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/families/{$familyId}", [
            'family_name' => 'Staff Household Updated',
        ])->assertOk()->assertJsonPath('data.family_name', 'Staff Household Updated');

        $memberId = $this->postJson("/api/families/{$familyId}/members", $this->memberPayload('Staff', 'Member'))
            ->assertCreated()
            ->json('data.id');
        $this->putJson("/api/families/{$familyId}/members/{$memberId}", [
            'first_name' => 'Staffer',
            'last_name' => 'Member',
            'date_of_birth' => now()->subYears(28)->toDateString(),
        ])->assertOk();

        $sacramentId = $this->postJson('/api/sacraments', $this->baptismPayload('Staff Recipient'))
            ->assertCreated()
            ->json('data.id');
        $this->putJson("/api/sacraments/{$sacramentId}", [
            'recipient_name' => 'Staff Recipient Updated',
        ])->assertOk();

        $this->postJson(
            '/api/tenant/donations/payments',
            $this->paymentPayload($this->familyA, '10.00')
        )->assertCreated();

        $bccId = $this->postJson('/api/bccs', $this->bccPayload(['name' => 'Staff BCC']))
            ->assertCreated()
            ->json('data.id');
        $this->putJson("/api/bccs/{$bccId}", ['name' => 'Staff BCC Updated'])->assertOk();

        $this->deleteJson("/api/families/{$familyId}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Unauthorized. Only Tenant Administrators can delete families.');
        $this->assertDatabaseHas('families', ['id' => $familyId, 'deleted_at' => null]);

        $this->deleteJson("/api/bccs/{$bccId}")->assertForbidden();
        $this->assertDatabaseHas('bccs', ['id' => $bccId, 'deleted_at' => null]);

        $this->deleteJson("/api/sacraments/{$sacramentId}")->assertForbidden();
        $this->assertDatabaseHas('sacraments', ['id' => $sacramentId, 'deleted_at' => null]);

        $this->postJson("/api/sacraments/{$this->sacramentA->id}/void", [
            'lock_version' => $this->sacramentA->lock_version,
            'reason' => 'Staff should not void',
        ])->assertForbidden();
        $this->assertDatabaseHas('sacraments', [
            'id' => $this->sacramentA->id,
            'deleted_at' => null,
        ]);

        $this->postJson("/api/tenant/donations/payments/{$this->paymentA->id}/reverse", [
            'reason' => 'nope',
        ])->assertForbidden();
        $this->postJson("/api/tenant/donations/payments/{$this->paymentA->id}/refunds", [
            'amount' => '1.00',
            'refund_date' => now()->toDateString(),
            'reason' => 'nope',
        ])->assertForbidden();
        $this->postJson('/api/tenant/donations/categories', [
            'name' => 'Hacked Category',
            'code' => 'HACK',
            'is_tax_deductible' => false,
        ])->assertForbidden();
    }

    #[Test]
    public function parishioner_is_limited_to_own_family_and_blocked_from_parish_management(): void
    {
        $this->asParishioner($this->tenantA, $this->personA);

        $this->getJson("/api/families/{$this->familyA->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->familyA->id);

        $this->getJson("/api/families/{$this->familyAOther->id}")->assertNotFound();

        $this->putJson("/api/families/{$this->familyA->id}", [
            'family_name' => 'Hacked',
        ])->assertForbidden();

        $this->postJson('/api/families', [
            'family_name' => 'Parishioner Family',
            'status' => 'active',
        ])->assertForbidden();

        $this->postJson("/api/families/{$this->familyA->id}/members", $this->memberPayload('Nope', 'Kid'))
            ->assertForbidden();

        $this->getJson('/api/members?per_page=50')->assertForbidden();
        $this->getJson('/api/sacraments')->assertForbidden();
        $this->getJson('/api/bccs')->assertForbidden();
        $this->getJson('/api/tenant/donations/payments')->assertForbidden();
        $this->getJson('/api/tenant/roles')->assertForbidden();
    }

    #[Test]
    public function super_admin_manages_platform_tenants_but_has_no_home_parish_scope(): void
    {
        $this->switchTo($this->superAdmin);

        $this->getJson('/api/tenant/list')->assertOk();
        $this->postJson('/api/tenant', [])->assertStatus(422);

        $this->getJson('/api/families')->assertForbidden();
        $this->assertDoesNotLeakForeignResource(
            $this->getJson("/api/families/{$this->familyB->id}"),
            'BravoRbacFamily'
        );
        $this->getJson('/api/sacraments')->assertForbidden();
        $this->assertDoesNotLeakForeignResource(
            $this->getJson("/api/sacraments/{$this->sacramentB->id}"),
            'BravoRbacRecipient'
        );
        $this->assertDoesNotLeakForeignResource(
            $this->getJson("/api/bccs/{$this->bccB->id}"),
            'BravoRbacBcc'
        );
        $this->getJson('/api/tenant/roles')->assertForbidden();
    }

    #[Test]
    public function deactivated_account_cannot_login_or_call_apis(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => $this->deactivatedAdmin->email,
            'password' => 'password',
        ])->assertStatus(422)
            ->assertJsonFragment(['Your account has been deactivated. Please contact support.']);

        $this->switchTo($this->deactivatedAdmin);
        $this->getJson('/api/families')->assertUnauthorized();
        $this->getJson('/api/sacraments')->assertUnauthorized();
        $this->getJson('/api/bccs')->assertUnauthorized();
        $this->getJson('/api/tenant/donations/payments')->assertUnauthorized();
        $this->getJson('/api/members')->assertUnauthorized();
    }

    #[Test]
    public function staff_cannot_escalate_to_parish_admin_or_alter_permissions(): void
    {
        $this->switchTo($this->staffA);

        $this->putJson("/api/tenant/users/{$this->staffA->id}/roles", [
            'role_ids' => [$this->adminARole->id],
        ])->assertForbidden()
            ->assertJsonPath('message', 'Tenant administrator access required.');

        $this->putJson("/api/tenant/roles/{$this->adminARole->id}/permissions", [
            'permission_ids' => [1],
        ])->assertForbidden()
            ->assertJsonPath('message', 'Tenant administrator access required.');

        $this->postJson('/api/permissions/bulk-assign-to-role', [
            'role_id' => $this->staffARole->id,
            'permission_ids' => [1],
        ])->assertForbidden();

        $this->putJson("/api/tenant/users/{$this->adminA->id}/roles", [
            'role_ids' => [$this->staffARole->id],
        ])->assertForbidden();

        $this->staffA->refresh();
        $this->assertSame($this->staffARole->id, (int) $this->staffA->role_id);
        $this->assertFalse($this->staffA->isTenantAdmin());
    }

    private function assertCrossTenantDenied(): void
    {
        $this->getJson("/api/families/{$this->familyB->id}")->assertNotFound();
        $this->putJson("/api/families/{$this->familyB->id}", ['family_name' => 'Hacked'])->assertNotFound();
        $familyDelete = $this->deleteJson("/api/families/{$this->familyB->id}");
        $this->assertContains($familyDelete->status(), [403, 404]);
        $this->assertDatabaseHas('families', [
            'id' => $this->familyB->id,
            'family_name' => 'BravoRbacFamily',
            'deleted_at' => null,
        ]);

        $this->getJson("/api/families/{$this->familyB->id}/members")->assertNotFound();
        $this->postJson("/api/families/{$this->familyB->id}/members", $this->memberPayload('Hack', 'Kid'))
            ->assertNotFound();
        $this->putJson(
            "/api/families/{$this->familyB->id}/members/{$this->memberB->id}",
            ['first_name' => 'Hacked']
        )->assertNotFound();
        $this->deleteJson("/api/families/{$this->familyB->id}/members/{$this->memberB->id}")->assertNotFound();

        $this->getJson("/api/persons/{$this->personB->id}")->assertStatus(422);

        $this->getJson("/api/sacraments/{$this->sacramentB->id}")->assertForbidden();
        $this->putJson("/api/sacraments/{$this->sacramentB->id}", [
            'recipient_name' => 'Hacked',
        ])->assertForbidden();
        $this->deleteJson("/api/sacraments/{$this->sacramentB->id}")->assertForbidden();
        $this->assertDatabaseHas('sacraments', [
            'id' => $this->sacramentB->id,
            'recipient_name' => 'BravoRbacRecipient',
            'deleted_at' => null,
        ]);

        $this->getJson("/api/tenant/donations/payments/{$this->paymentB->id}/receipt")->assertNotFound();
        $reverse = $this->postJson("/api/tenant/donations/payments/{$this->paymentB->id}/reverse", [
            'reason' => 'steal',
        ]);
        $this->assertContains($reverse->status(), [403, 404]);
        $this->postJson(
            '/api/tenant/donations/payments',
            $this->paymentPayload($this->familyB, '10.00')
        )->assertStatus(422);

        $this->getJson("/api/bccs/{$this->bccB->id}")->assertNotFound();
        $this->putJson("/api/bccs/{$this->bccB->id}", ['name' => 'Hacked'])->assertNotFound();
        $bccDelete = $this->deleteJson("/api/bccs/{$this->bccB->id}");
        $this->assertContains($bccDelete->status(), [403, 404]);
        $this->assertDatabaseHas('bccs', [
            'id' => $this->bccB->id,
            'name' => 'BravoRbacBcc',
            'deleted_at' => null,
        ]);
    }

    private function seedTenantRecords(): void
    {
        $this->familyA = Family::factory()->active()->create([
            'tenant_id' => $this->tenantA->id,
            'family_name' => 'AlphaRbacFamily',
        ]);
        $this->familyAOther = Family::factory()->active()->create([
            'tenant_id' => $this->tenantA->id,
            'family_name' => 'AlphaOtherFamily',
        ]);
        $this->familyB = Family::factory()->active()->create([
            'tenant_id' => $this->tenantB->id,
            'family_name' => 'BravoRbacFamily',
        ]);

        $this->memberA = FamilyMember::factory()->head()->active()->create([
            'family_id' => $this->familyA->id,
            'first_name' => 'AlphaRbac',
            'last_name' => 'Member',
            'date_of_birth' => '1990-05-05',
        ]);
        FamilyMember::factory()->active()->create(['family_id' => $this->familyA->id]);
        FamilyMember::factory()->active()->create(['family_id' => $this->familyAOther->id]);

        $this->memberB = FamilyMember::factory()->head()->active()->create([
            'family_id' => $this->familyB->id,
            'first_name' => 'BravoRbac',
            'last_name' => 'Member',
            'date_of_birth' => '1988-03-03',
        ]);
        FamilyMember::factory()->active()->create(['family_id' => $this->familyB->id]);

        $this->personA = Person::query()->findOrFail($this->memberA->person_id);
        $this->personB = Person::query()->findOrFail($this->memberB->person_id);

        $this->sacramentA = Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenantA->id,
            'sacrament_type_id' => $this->baptismType->id,
            'recipient_name' => 'AlphaRbacRecipient',
            'lock_version' => 0,
        ]);
        $this->sacramentB = Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenantB->id,
            'sacrament_type_id' => $this->baptismType->id,
            'recipient_name' => 'BravoRbacRecipient',
            'lock_version' => 0,
        ]);

        $this->bccA = BCC::factory()->active()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'AlphaRbacBcc',
        ]);
        $this->bccB = BCC::factory()->active()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'BravoRbacBcc',
        ]);

        $this->switchTo($this->adminA);
        $paymentAId = $this->postJson(
            '/api/tenant/donations/payments',
            $this->paymentPayload($this->familyA, '40.00', ['payer_name' => 'AlphaRbacFamily'])
        )->assertCreated()->json('data.id');

        $this->switchTo($this->adminB);
        $paymentBId = $this->postJson(
            '/api/tenant/donations/payments',
            $this->paymentPayload($this->familyB, '80.00', ['payer_name' => 'BravoRbacFamily'])
        )->assertCreated()->json('data.id');

        $this->paymentA = DonationPayment::query()->findOrFail($paymentAId);
        $this->paymentB = DonationPayment::query()->findOrFail($paymentBId);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function baptismPayload(string $recipientName, array $overrides = []): array
    {
        return array_merge([
            'sacrament_type_id' => $this->baptismType->id,
            'recipient_name' => $recipientName,
            'date_administered' => '2025-01-15',
            'place_administered' => 'St. Mary Church',
            'recipient_birth_date' => '2015-04-10',
            'recipient_birth_place' => 'Parish City',
            'recipient_gender' => 'male',
            'father_name' => 'Joseph Father',
            'mother_name' => 'Mary Mother',
            'minister_name' => 'Fr. Joseph',
            'minister_title' => 'Fr.',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function memberPayload(string $first, string $last): array
    {
        return [
            'first_name' => $first,
            'last_name' => $last,
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'gender' => 'female',
            'relationship_to_head' => 'daughter',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function paymentPayload(Family $family, string $amount = '100.00', array $overrides = []): array
    {
        return array_merge([
            'family_id' => $family->id,
            'payer_name' => $family->family_name ?? 'Test Payer',
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
            'method' => 'cash',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function bccPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Certification BCC',
            'description' => 'Test community',
            'meeting_day' => 'sunday',
            'meeting_time' => '10:00',
            'meeting_frequency' => 'Weekly',
            'status' => 'active',
        ], $overrides);
    }

    private function pluckIds(TestResponse $response, string $path): Collection
    {
        $payload = $response->json($path);
        if (! is_array($payload)) {
            $payload = $response->json('data') ?? [];
        }

        return collect($payload)->pluck('id')->filter()->values();
    }

    private function assertDoesNotLeakForeignResource(TestResponse $response, string $marker): void
    {
        $this->assertFalse($response->isSuccessful(), $response->getContent());
        $this->assertStringNotContainsString($marker, $response->getContent());
    }
}
