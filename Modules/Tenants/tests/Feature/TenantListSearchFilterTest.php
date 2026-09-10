<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class TenantListSearchFilterTest extends TestCase
{
    use ActsAsTenantRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSuperAdmin();
    }

    #[Test]
    public function it_denies_tenant_list_for_non_platform_admins(): void
    {
        $this->asTenantAdmin();

        $this->getJson('/api/tenant/list')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function it_filters_tenants_by_search_term_across_name_slug_and_contact_fields(): void
    {
        $alpha = Tenant::factory()->create([
            'name' => 'Alpha Parish Church',
            'slug' => 'alpha-parish',
            'active' => 1,
        ]);
        $beta = Tenant::factory()->create([
            'name' => 'Beta Community',
            'slug' => 'beta-community',
            'active' => 1,
        ]);

        User::factory()->create([
            'tenant_id' => $alpha->id,
            'user_type' => User::USER_TYPE_PRIMARY_CONTACT,
            'name' => 'Alpha Contact',
            'email' => 'alpha@example.test',
            'contact_number' => '+91-9000000001',
        ]);
        User::factory()->create([
            'tenant_id' => $beta->id,
            'user_type' => User::USER_TYPE_PRIMARY_CONTACT,
            'name' => 'Beta Contact',
            'email' => 'beta@example.test',
            'contact_number' => '+91-9000000002',
        ]);

        $this->getJson('/api/tenant/list?search=Alpha&per_page=all')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $alpha->id);

        $this->getJson('/api/tenant/list?search=alpha@example.test&per_page=all')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $alpha->id);

        $this->getJson('/api/tenant/list?search=9000000002&per_page=all')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $beta->id);
    }

    #[Test]
    public function it_filters_tenants_by_plan_tier_and_active_status(): void
    {
        Tenant::factory()->create([
            'name' => 'Premium Parish',
            'slug' => 'premium-parish',
            'plan' => 'premium',
            'tenant_tier' => 'parish',
            'active' => 1,
        ]);
        Tenant::factory()->create([
            'name' => 'Free Diocese',
            'slug' => 'free-diocese',
            'plan' => 'free',
            'tenant_tier' => 'diocese',
            'active' => 0,
        ]);

        $this->getJson('/api/tenant/list?plan=premium&per_page=all')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.plan', 'premium');

        $this->getJson('/api/tenant/list?tenant_tier=diocese&per_page=all')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.tenant_tier', 'diocese');

        $this->getJson('/api/tenant/list?active=0&per_page=all')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.active', 0);
    }

    #[Test]
    public function it_filters_tenants_by_archdiocese_and_subscription_status(): void
    {
        $archdioceseId = DB::table('archdioceses')->insertGetId([
            'name' => 'Diocese of Filter Test',
            'code' => 'FILTER_TEST_'.uniqid('', true),
            'country' => 'India',
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $matched = Tenant::factory()->create([
            'name' => 'Matched Parish',
            'slug' => 'matched-parish',
            'trial_ends_at' => now()->addDays(7),
            'subscription_ends_at' => now()->addYear(),
            'subscription_suspended_at' => null,
            'active' => 1,
        ]);
        $other = Tenant::factory()->create([
            'name' => 'Other Parish',
            'slug' => 'other-parish',
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->subDay(),
            'subscription_suspended_at' => null,
            'active' => 1,
        ]);

        ChurchProfile::query()->create([
            'tenant_id' => $matched->id,
            'archdiocese_id' => $archdioceseId,
        ]);
        ChurchProfile::query()->create([
            'tenant_id' => $other->id,
            'archdiocese_id' => null,
        ]);

        $this->getJson("/api/tenant/list?archdiocese_id={$archdioceseId}&per_page=all")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $matched->id);

        $this->getJson('/api/tenant/list?subscription_status=trial&per_page=all')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $matched->id);

        $this->getJson('/api/tenant/list?subscription_status=expired&per_page=all')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $other->id);
    }

    #[Test]
    public function it_includes_resolved_subscription_status_on_tenant_list_items(): void
    {
        $active = Tenant::factory()->create([
            'name' => 'Active Sub Parish',
            'slug' => 'active-sub-parish',
            'subscription_ends_at' => now()->addYear(),
            'subscription_suspended_at' => null,
            'active' => 1,
        ]);
        $expired = Tenant::factory()->create([
            'name' => 'Expired Sub Parish',
            'slug' => 'expired-sub-parish',
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->subDays(30),
            'subscription_suspended_at' => null,
            'active' => 1,
        ]);

        $this->getJson('/api/tenant/list?search=Active+Sub&per_page=all')
            ->assertOk()
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.subscription_status', 'ACTIVE')
            ->assertJsonPath('data.0.access_mode', 'full');

        $this->getJson('/api/tenant/list?search=Expired+Sub&per_page=all')
            ->assertOk()
            ->assertJsonPath('data.0.id', $expired->id)
            ->assertJsonPath('data.0.subscription_status', 'EXPIRED')
            ->assertJsonPath('data.0.access_mode', 'read_only');
    }

    #[Test]
    public function it_combines_search_and_filters_with_pagination_and_safe_sorting(): void
    {
        Tenant::factory()
            ->count(3)
            ->sequence(fn ($sequence) => [
                'plan' => 'basic',
                'active' => 1,
                'name' => 'Searchable Basic Tenant '.$sequence->index,
                'slug' => 'searchable-basic-'.$sequence->index,
            ])
            ->create();
        Tenant::factory()
            ->count(2)
            ->sequence(fn ($sequence) => [
                'plan' => 'premium',
                'active' => 1,
                'name' => 'Searchable Premium Tenant '.$sequence->index,
                'slug' => 'searchable-premium-'.$sequence->index,
            ])
            ->create();

        $response = $this->getJson('/api/tenant/list?search=Searchable&plan=basic&per_page=2&page=1&sort_by=name&sort_order=asc');
        $response->assertOk()
            ->assertJsonPath('pagination.total', 3)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonCount(2, 'data');

        $invalidSort = $this->getJson('/api/tenant/list?sort_by=invalid_column&sort_order=asc&per_page=all');
        $invalidSort->assertOk();
        $this->assertNotEmpty($invalidSort->json('data'));
    }

    #[Test]
    public function it_includes_user_counts_on_tenant_list_items(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Counted Parish',
            'slug' => 'counted-parish',
            'active' => 1,
            'max_users' => 100,
        ]);

        User::factory()->count(2)->create([
            'tenant_id' => $tenant->id,
            'active' => 1,
        ]);
        User::factory()->create([
            'tenant_id' => $tenant->id,
            'active' => 0,
        ]);

        $this->getJson('/api/tenant/list?search=Counted&per_page=all')
            ->assertOk()
            ->assertJsonPath('data.0.users_count', 3)
            ->assertJsonPath('data.0.active_users_count', 2);
    }

    #[Test]
    public function it_resets_to_default_sort_when_sort_column_is_not_allowed(): void
    {
        Tenant::factory()->create([
            'name' => 'Older Tenant',
            'slug' => 'older-tenant',
            'created_at' => now()->subDays(2),
        ]);
        Tenant::factory()->create([
            'name' => 'Newer Tenant',
            'slug' => 'newer-tenant',
            'created_at' => now()->subDay(),
        ]);

        $response = $this->getJson('/api/tenant/list?sort_by=password&per_page=all');
        $response->assertOk();

        $this->assertSame('Newer Tenant', $response->json('data.0.name'));
    }
}
