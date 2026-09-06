<?php

namespace Modules\Tenants\Tests\Unit;

use Modules\Tenants\Support\ApiPagination;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiPaginationTest extends TestCase
{
    #[Test]
    public function it_clamps_per_page_to_configured_maximum(): void
    {
        config([
            'tenants.api.pagination.max_per_page' => 100,
            'tenants.api.pagination.default_per_page' => 20,
        ]);

        $this->assertSame(100, ApiPagination::clamp(500));
        $this->assertSame(1, ApiPagination::clamp(0));
        $this->assertSame(20, ApiPagination::clamp(null));
    }

    #[Test]
    public function it_returns_configured_defaults(): void
    {
        config([
            'tenants.api.pagination.max_per_page' => 50,
            'tenants.api.pagination.default_per_page' => 15,
        ]);

        $this->assertSame(50, ApiPagination::maxPerPage());
        $this->assertSame(15, ApiPagination::defaultPerPage());
    }
}
