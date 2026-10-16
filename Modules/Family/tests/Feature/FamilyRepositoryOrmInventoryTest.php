<?php

namespace Modules\Family\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Family\app\Repositories\FamilyRepository;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContextBinder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Documents Cycle 3 ORM inventory: production Family::find removed; service layer validates tenant.
 */
class FamilyRepositoryOrmInventoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function family_service_path_rejects_foreign_family_before_loading_members(): void
    {
        $tenantA = Tenant::factory()->active()->create();
        $tenantB = Tenant::factory()->active()->create();

        $foreign = Family::factory()->create([
            'tenant_id' => $tenantB->id,
            'family_name' => 'Victim Family',
            'head_of_family' => 'Original Head',
            'status' => 'active',
        ]);

        TenantContextBinder::bind($tenantA->id);

        app(FamilyRepository::class)->syncHeadOfFamily($foreign->id);

        $this->assertSame('Original Head', $foreign->fresh()->head_of_family);
    }
}
