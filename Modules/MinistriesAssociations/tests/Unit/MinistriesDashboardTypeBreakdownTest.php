<?php

namespace Modules\MinistriesAssociations\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationCategory;
use Modules\MinistriesAssociations\Models\OrganizationType;
use Modules\MinistriesAssociations\Services\MinistriesDashboardService;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinistriesDashboardTypeBreakdownTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function active_by_type_lists_tenant_types_with_counts_including_zeros(): void
    {
        $tenant = Tenant::factory()->create();
        $userId = 1;

        $choirType = OrganizationType::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'choir',
            'name' => 'Choir',
            'is_system' => true,
            'is_active' => true,
            'display_order' => 1,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        $fellowshipType = OrganizationType::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'fellowship',
            'name' => 'Fellowship',
            'is_system' => true,
            'is_active' => true,
            'display_order' => 2,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        $category = OrganizationCategory::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'spiritual',
            'name' => 'Spiritual',
            'is_system' => true,
            'is_active' => true,
            'display_order' => 1,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        Organization::query()->create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'type_id' => $choirType->id,
            'code' => 'CHOIR-1',
            'name' => 'Parish Choir',
            'status' => Organization::STATUS_ACTIVE,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        Organization::query()->create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'type_id' => $fellowshipType->id,
            'code' => 'FEL-1',
            'name' => 'Youth Fellowship',
            'status' => Organization::STATUS_ACTIVE,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $summary = app(MinistriesDashboardService::class)->summary((int) $tenant->id);
        $byType = $summary['organizations']['active_by_type'];

        $this->assertSame(2, $summary['organizations']['active']);
        $this->assertCount(2, $byType);
        $this->assertSame('choir', $byType[0]['code']);
        $this->assertSame(1, $byType[0]['count']);
        $this->assertSame('fellowship', $byType[1]['code']);
        $this->assertSame(1, $byType[1]['count']);

        $byCategory = $summary['organizations']['category_breakdown'];
        $this->assertSame(2, $byCategory[0]['count']);
        $this->assertSame('spiritual', $byCategory[0]['code']);
    }
}
