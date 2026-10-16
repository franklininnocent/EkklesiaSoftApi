<?php

namespace Modules\Family\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Family\app\Repositories\FamilyRepository;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContextBinder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FamilyRepositoryTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sync_head_of_family_does_not_update_another_tenants_family_when_context_is_bound(): void
    {
        $tenantA = Tenant::factory()->active()->create();
        $tenantB = Tenant::factory()->active()->create();

        $foreign = Family::factory()->create([
            'tenant_id' => $tenantB->id,
            'family_name' => 'Victim Family',
            'head_of_family' => 'Original Head',
            'status' => 'active',
        ]);

        FamilyMember::factory()->create([
            'tenant_id' => $tenantB->id,
            'family_id' => $foreign->id,
            'first_name' => 'Updated',
            'last_name' => 'Head',
            'relationship_to_head' => 'head',
            'status' => 'active',
        ]);

        TenantContextBinder::bind($tenantA->id);

        app(FamilyRepository::class)->syncHeadOfFamily($foreign->id);

        $this->assertSame('Original Head', $foreign->fresh()->head_of_family);
    }

    #[Test]
    public function sync_head_of_family_updates_when_bound_tenant_owns_the_family(): void
    {
        $tenant = Tenant::factory()->active()->create();

        $family = Family::factory()->create([
            'tenant_id' => $tenant->id,
            'family_name' => 'Parish Family',
            'head_of_family' => 'Original Head',
            'status' => 'active',
        ]);

        FamilyMember::factory()->create([
            'tenant_id' => $tenant->id,
            'family_id' => $family->id,
            'first_name' => 'Updated',
            'last_name' => 'Head',
            'relationship_to_head' => 'head',
            'status' => 'active',
        ]);

        TenantContextBinder::bind($tenant->id);

        app(FamilyRepository::class)->syncHeadOfFamily($family->id);

        $this->assertSame('Updated Head', $family->fresh()->head_of_family);
    }
}
