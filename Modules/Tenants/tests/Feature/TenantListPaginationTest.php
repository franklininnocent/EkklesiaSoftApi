<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class TenantListPaginationTest extends TestCase
{
    use ActsAsTenantRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSuperAdmin();
    }

    #[Test]
    public function it_returns_paginated_tenant_list_with_correct_metadata_for_75_total_64_active(): void
    {
        $this->seedTenants(64, 11);

        $response = $this->getJson('/api/tenant/list?per_page=20&page=1');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('pagination.total', 75)
            ->assertJsonPath('pagination.per_page', 20)
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.from', 1)
            ->assertJsonPath('pagination.to', 20)
            ->assertJsonPath('pagination.last_page', 4)
            ->assertJsonCount(20, 'data');

        $activeResponse = $this->getJson('/api/tenant/list?active=1&per_page=all');
        $activeResponse->assertOk()
            ->assertJsonPath('total', 64)
            ->assertJsonCount(64, 'data');
    }

    #[Test]
    public function it_returns_all_tenants_without_duplicates_when_paginating_through_pages(): void
    {
        $this->seedTenants(64, 11);

        $allIds = [];

        for ($page = 1; $page <= 4; $page++) {
            $response = $this->getJson("/api/tenant/list?per_page=20&page={$page}");
            $response->assertOk();

            $ids = collect($response->json('data'))->pluck('id')->all();
            $allIds = array_merge($allIds, $ids);

            if ($page < 4) {
                $response->assertJsonCount(20, 'data');
            } else {
                $response->assertJsonCount(15, 'data');
            }
        }

        $this->assertCount(75, $allIds);
        $this->assertCount(75, array_unique($allIds));
    }

    #[Test]
    public function it_returns_all_tenants_when_per_page_is_all(): void
    {
        $this->seedTenants(64, 11);

        $response = $this->getJson('/api/tenant/list?per_page=all');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total', 75)
            ->assertJsonCount(75, 'data');
    }

    private function seedTenants(int $activeCount, int $inactiveCount): void
    {
        Tenant::factory()
            ->count($activeCount)
            ->active()
            ->sequence(fn ($sequence) => [
                'slug' => 'active-tenant-'.$sequence->index,
                'domain' => 'active-'.$sequence->index.'.example.test',
            ])
            ->create();

        Tenant::factory()
            ->count($inactiveCount)
            ->inactive()
            ->sequence(fn ($sequence) => [
                'slug' => 'inactive-tenant-'.$sequence->index,
                'domain' => 'inactive-'.$sequence->index.'.example.test',
            ])
            ->create();
    }
}
