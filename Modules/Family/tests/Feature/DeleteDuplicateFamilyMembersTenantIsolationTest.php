<?php

namespace Modules\Family\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeleteDuplicateFamilyMembersTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function command_fails_without_tenant_id(): void
    {
        $this->artisan('family:delete-duplicates', ['--force' => true])
            ->assertFailed();
    }

    #[Test]
    public function command_does_not_delete_duplicates_in_another_tenant(): void
    {
        $tenantA = Tenant::factory()->active()->create();
        $tenantB = Tenant::factory()->active()->create();

        $familyA = Family::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'active']);
        $familyB = Family::factory()->create(['tenant_id' => $tenantB->id, 'status' => 'active']);

        FamilyMember::factory()->create([
            'tenant_id' => $tenantA->id,
            'family_id' => $familyA->id,
            'first_name' => 'Same',
            'last_name' => 'Name',
            'status' => 'active',
        ]);
        FamilyMember::factory()->create([
            'tenant_id' => $tenantA->id,
            'family_id' => $familyA->id,
            'first_name' => 'Same',
            'last_name' => 'Name',
            'status' => 'active',
        ]);

        $keepB = FamilyMember::factory()->create([
            'tenant_id' => $tenantB->id,
            'family_id' => $familyB->id,
            'first_name' => 'Same',
            'last_name' => 'Name',
            'status' => 'active',
        ]);
        $dupB = FamilyMember::factory()->create([
            'tenant_id' => $tenantB->id,
            'family_id' => $familyB->id,
            'first_name' => 'Same',
            'last_name' => 'Name',
            'status' => 'active',
        ]);

        $this->artisan('family:delete-duplicates', [
            '--tenant-id' => $tenantA->id,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertNotNull($keepB->fresh());
        $this->assertNotNull($dupB->fresh());
        $this->assertSame(1, FamilyMember::query()->where('family_id', $familyA->id)->count());
    }
}
