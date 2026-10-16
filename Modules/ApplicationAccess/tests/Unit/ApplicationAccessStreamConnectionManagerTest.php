<?php

namespace Modules\ApplicationAccess\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Modules\ApplicationAccess\Exceptions\ApplicationAccessStreamCapacityExceededException;
use Modules\ApplicationAccess\Services\ApplicationAccessStreamConnectionManager;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationAccessStreamConnectionManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'applicationaccess.sse.max_connections' => 2,
            'applicationaccess.sse.ttl_seconds' => 120,
        ]);
    }

    #[Test]
    public function second_connection_for_same_user_marks_first_as_replaced(): void
    {
        $manager = app(ApplicationAccessStreamConnectionManager::class);

        $first = $manager->acquire(42);
        $second = $manager->acquire(42);

        $this->assertTrue($manager->isReplaced($first, 42));
        $this->assertFalse($manager->isReplaced($second, 42));
    }

    #[Test]
    public function global_capacity_limit_throws_when_exceeded(): void
    {
        $manager = app(ApplicationAccessStreamConnectionManager::class);

        $manager->acquire(1);
        $manager->acquire(2);

        $this->expectException(ApplicationAccessStreamCapacityExceededException::class);
        $manager->acquire(3);
    }

    #[Test]
    public function release_removes_connection_from_global_pool(): void
    {
        $manager = app(ApplicationAccessStreamConnectionManager::class);

        $connection = $manager->acquire(9);
        $manager->release($connection, 9);

        $this->assertTrue($manager->hasCapacity());
        $this->assertCount(0, $manager->connections());
    }
}
