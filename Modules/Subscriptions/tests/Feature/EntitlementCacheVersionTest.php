<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Subscriptions\Support\EntitlementCacheVersion;
use Modules\Tenants\Support\TenantCacheVersion;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Database cache ignores increments on missing keys. Version bumpers seed first so
 * Redis (production default) and the database fallback stay consistent.
 */
class EntitlementCacheVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'database']);
        Cache::forgetDriver('database');
    }

    #[Test]
    public function catalog_and_tenant_versions_advance_on_the_database_store(): void
    {
        $this->assertSame(0, EntitlementCacheVersion::catalog());
        EntitlementCacheVersion::bumpCatalog();
        EntitlementCacheVersion::bumpCatalog();
        $this->assertSame(2, EntitlementCacheVersion::catalog());

        $before = EntitlementCacheVersion::entitlementsKey(7);
        EntitlementCacheVersion::bumpTenant(7);
        $this->assertSame(1, EntitlementCacheVersion::tenant(7));
        $this->assertNotSame($before, EntitlementCacheVersion::entitlementsKey(7));
    }

    #[Test]
    public function tenant_cache_version_advances_on_the_database_store(): void
    {
        $this->assertSame(1, TenantCacheVersion::bump(3));
        $this->assertSame(2, TenantCacheVersion::bump(3));
        $this->assertSame(2, TenantCacheVersion::current(3));
    }
}
