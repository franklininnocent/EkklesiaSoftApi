<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Modules\Tenants\Services\PlatformHealthService;
use Modules\Tenants\Support\TenantCacheVersion;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RedisCacheStoreTest extends TestCase
{
    #[Test]
    public function redis_store_is_configured_on_the_cache_connection(): void
    {
        $this->assertSame('redis', config('cache.stores.redis.driver'));
        $this->assertSame('cache', config('cache.stores.redis.connection'));
        $this->assertSame('predis', config('database.redis.client'));
        $this->assertSame('1', (string) config('database.redis.cache.database'));
    }

    #[Test]
    public function tenant_scoped_keys_do_not_collide_across_tenants(): void
    {
        $a = TenantCacheVersion::scopedKey(11, 'family_dashboard', 'summary');
        $b = TenantCacheVersion::scopedKey(12, 'family_dashboard', 'summary');

        $this->assertStringContainsString('tenant_11', $a);
        $this->assertStringContainsString('tenant_12', $b);
        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function redis_remember_round_trips_when_redis_is_available(): void
    {
        try {
            Redis::connection('cache')->ping();
        } catch (\Throwable $exception) {
            $this->markTestSkipped('Redis is not available: '.$exception->getMessage());
        }

        config(['cache.default' => 'redis']);
        Cache::forgetDriver('redis');

        $key = 'redis_cache_probe:'.uniqid('', true);
        Cache::put($key, ['tenant_id' => 9], 30);

        $this->assertSame(['tenant_id' => 9], Cache::get($key));

        Cache::forget($key);
        $this->assertNull(Cache::get($key));
    }

    #[Test]
    public function health_reports_degraded_when_redis_is_unreachable(): void
    {
        config(['cache.default' => 'redis']);
        Redis::shouldReceive('connection')->once()->andThrow(new \RuntimeException('redis_down'));

        $snapshot = app(PlatformHealthService::class)->snapshot();

        $this->assertSame('degraded', $snapshot['checks']['cache']['status']);
        $this->assertSame('redis', $snapshot['checks']['cache']['driver']);
        $this->assertSame('cache_unavailable', $snapshot['checks']['cache']['message']);
    }
}
