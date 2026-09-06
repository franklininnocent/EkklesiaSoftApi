<?php

namespace Modules\Tenants\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Tenants\Support\TenantCacheVersion;
use Modules\Tenants\Support\TenantPrivateStorage;
use Tests\TestCase;

class TenantCacheVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_versioned_cache_keys(): void
    {
        $tenantId = 42;
        $first = TenantCacheVersion::scopedKey($tenantId, 'sacraments', 'list');
        TenantCacheVersion::bump($tenantId);
        $second = TenantCacheVersion::scopedKey($tenantId, 'sacraments', 'list');

        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('tenant_42:v_1:', $second);
    }

    public function test_remember_uses_bumped_version(): void
    {
        $tenantId = 7;
        $calls = 0;

        TenantCacheVersion::remember($tenantId, 'test', 'payload', 60, function () use (&$calls) {
            $calls++;

            return 'value-a';
        });

        TenantCacheVersion::bump($tenantId);

        $value = TenantCacheVersion::remember($tenantId, 'test', 'payload', 60, function () use (&$calls) {
            $calls++;

            return 'value-b';
        });

        $this->assertSame(2, $calls);
        $this->assertSame('value-b', $value);
    }

    public function test_private_storage_paths_are_tenant_scoped(): void
    {
        $path = TenantPrivateStorage::relativePath(9, 'sacrament-certificates/15', 'file.pdf');

        $this->assertSame('tenants/9/sacrament-certificates/15/file.pdf', $path);
        $this->assertNull(TenantPrivateStorage::normalize('../escape'));
    }
}
