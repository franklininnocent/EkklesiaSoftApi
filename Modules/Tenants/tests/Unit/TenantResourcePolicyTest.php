<?php

namespace Modules\Tenants\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Policies\DonationPaymentPolicy;
use Modules\Family\Models\Family;
use Modules\Family\Models\Person;
use Modules\Family\Policies\FamilyPolicy;
use Modules\Family\Policies\PersonPolicy;
use Modules\PastoralCare\Models\PastoralCareRequest;
use Modules\PastoralCare\Policies\PastoralCareRequestPolicy;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Policies\SacramentPolicy;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContextBinder;
use Tests\TestCase;

class TenantResourcePolicyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Tenant $otherTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->active()->create();
        $this->otherTenant = Tenant::factory()->active()->create();
    }

    public function test_viewer_cannot_mutate_family_records(): void
    {
        $user = $this->viewerUser(['families.view']);
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id]);
        $policy = app(FamilyPolicy::class);

        TenantContextBinder::bind($this->tenant->id, (int) $user->id, $this->tenant->id);

        $this->assertTrue($policy->view($user, $family));
        $this->assertFalse($policy->update($user, $family));
        $this->assertFalse($policy->delete($user, $family));
    }

    public function test_family_policy_rejects_cross_tenant_resource(): void
    {
        $user = $this->viewerUser(['families.view', 'families.edit']);
        $foreignFamily = Family::factory()->create(['tenant_id' => $this->otherTenant->id]);
        $policy = app(FamilyPolicy::class);

        TenantContextBinder::bind($this->tenant->id, (int) $user->id, $this->tenant->id);

        $this->assertFalse($policy->view($user, $foreignFamily));
        $this->assertFalse($policy->update($user, $foreignFamily));
    }

    public function test_person_policy_requires_families_view_permission(): void
    {
        $user = $this->viewerUser(['families.edit']);
        $person = Person::factory()->create(['tenant_id' => $this->tenant->id]);
        $policy = app(PersonPolicy::class);

        TenantContextBinder::bind($this->tenant->id, (int) $user->id, $this->tenant->id);

        $this->assertFalse($policy->view($user, $person));
    }

    public function test_sacrament_viewer_cannot_update_or_delete(): void
    {
        $user = $this->viewerUser(['sacraments.view']);
        $sacrament = Sacrament::factory()->create(['tenant_id' => $this->tenant->id]);
        $policy = app(SacramentPolicy::class);

        TenantContextBinder::bind($this->tenant->id, (int) $user->id, $this->tenant->id);

        $this->assertTrue($policy->view($user, $sacrament));
        $this->assertFalse($policy->update($user, $sacrament));
        $this->assertFalse($policy->delete($user, $sacrament));
    }

    public function test_donation_viewer_cannot_reverse_or_refund(): void
    {
        $user = $this->viewerUser(['donations.view']);
        $payment = DonationPayment::withoutTenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'payment_number' => 'PAY-POLICY-001',
            'payer_name' => 'Policy Test',
            'amount' => '15.00',
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'payment_date' => now()->toDateString(),
        ]);
        $policy = app(DonationPaymentPolicy::class);

        TenantContextBinder::bind($this->tenant->id, (int) $user->id, $this->tenant->id);

        $this->assertTrue($policy->view($user, $payment));
        $this->assertFalse($policy->reverse($user, $payment));
        $this->assertFalse($policy->refund($user, $payment));
    }

    public function test_pastoral_viewer_cannot_assign_or_cancel(): void
    {
        $user = $this->viewerUser(['pastoral.care.view']);
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id]);
        $request = PastoralCareRequest::query()->create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'type' => 'hospital_visit',
            'priority' => 'routine',
            'status' => 'open',
            'summary' => 'Policy test request',
            'created_by_user_id' => $user->id,
        ]);
        $policy = app(PastoralCareRequestPolicy::class);

        TenantContextBinder::bind($this->tenant->id, (int) $user->id, $this->tenant->id);

        $this->assertTrue($policy->view($user, $request));
        $this->assertFalse($policy->assign($user, $request));
        $this->assertFalse($policy->cancel($user, $request));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function viewerUser(array $permissions): User
    {
        $role = Role::create([
            'name' => 'Viewer',
            'description' => 'View-only staff',
            'level' => 3,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $permissionIds = [];
        foreach ($permissions as $name) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => 'Policy test permission',
                    'module' => 'Test',
                    'scope' => Permission::SCOPE_TENANT,
                    'category' => 'test',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            );
            $permissionIds[] = $permission->id;
        }
        $role->permissions()->sync($permissionIds);

        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);
        $user->clearPermissionsCache();

        return $user->fresh();
    }
}
