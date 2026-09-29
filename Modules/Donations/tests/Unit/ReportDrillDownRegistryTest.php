<?php

namespace Modules\Donations\Tests\Unit;

use Modules\Donations\Contracts\ReportDrillDownAdapter;
use Modules\Donations\Services\ReportDrillDown\ReportDrillDownRegistry;
use Modules\Donations\Support\ReportMetricCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportDrillDownRegistryTest extends TestCase
{
    #[Test]
    public function it_registers_all_leadership_report_graphs(): void
    {
        $registry = app(ReportDrillDownRegistry::class);

        $this->assertSame(ReportMetricCatalog::LEADERSHIP_GRAPH_IDS, $registry->registeredGraphIds());
        foreach (ReportMetricCatalog::LEADERSHIP_GRAPH_IDS as $graphId) {
            $this->assertTrue($registry->has($graphId));
            $this->assertSame($graphId, $registry->get($graphId)->graphId());
        }
    }

    #[Test]
    public function each_adapter_exposes_complete_metadata(): void
    {
        $registry = app(ReportDrillDownRegistry::class);

        foreach (ReportMetricCatalog::LEADERSHIP_GRAPH_IDS as $graphId) {
            $adapter = $registry->get($graphId);
            $this->assertInstanceOf(ReportDrillDownAdapter::class, $adapter);
            $this->assertNotEmpty($adapter->supportedDataElementIds());
            $this->assertNotEmpty($adapter->supportedSorts());
            $this->assertIsArray($adapter->supportedFilterKeys());
            $this->assertIsArray($adapter->supportedDimensions());
        }
    }
}
