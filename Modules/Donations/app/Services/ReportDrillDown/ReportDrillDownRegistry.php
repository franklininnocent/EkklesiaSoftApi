<?php

namespace Modules\Donations\Services\ReportDrillDown;

use Illuminate\Support\Collection;
use Modules\Donations\Contracts\ReportDrillDownAdapter;
use Modules\Donations\Support\ReportMetricCatalog;
use RuntimeException;

class ReportDrillDownRegistry
{
    /** @var Collection<string, ReportDrillDownAdapter> */
    private Collection $adapters;

    public function __construct(
        OutstandingOverdueDrillDownAdapter $outstandingOverdue,
        CollectionsDrillDownAdapter $collections,
        CollectionTrendDrillDownAdapter $collectionTrend,
        FamilyParticipationDrillDownAdapter $familyParticipation,
        MonthEndForecastDrillDownAdapter $monthEndForecast,
        FinancialHealthDrillDownAdapter $financialHealth,
        CollectionSnapshotDrillDownAdapter $collectionSnapshot
    ) {
        $this->adapters = collect([
            $outstandingOverdue->graphId() => $outstandingOverdue,
            $collections->graphId() => $collections,
            $collectionSnapshot->graphId() => $collectionSnapshot,
            $collectionTrend->graphId() => $collectionTrend,
            $familyParticipation->graphId() => $familyParticipation,
            $monthEndForecast->graphId() => $monthEndForecast,
            $financialHealth->graphId() => $financialHealth,
        ]);

        $this->assertRegistryIntegrity();
    }

    public function get(string $graphId): ReportDrillDownAdapter
    {
        $adapter = $this->adapters->get($graphId);
        if ($adapter === null) {
            throw new RuntimeException('Graph drill-down is not available yet.');
        }

        return $adapter;
    }

    public function has(string $graphId): bool
    {
        return $this->adapters->has($graphId);
    }

    /**
     * @return array<int, string>
     */
    public function registeredGraphIds(): array
    {
        return $this->adapters->keys()->values()->all();
    }

    private function assertRegistryIntegrity(): void
    {
        if ($this->adapters->count() !== $this->adapters->keys()->unique()->count()) {
            throw new RuntimeException('Duplicate report drill-down graph_id registration.');
        }

        if ($this->adapters->count() !== count(ReportMetricCatalog::LEADERSHIP_GRAPH_IDS)) {
            throw new RuntimeException('Leadership report drill-down registry is incomplete.');
        }

        foreach ($this->adapters as $graphId => $adapter) {
            if ($adapter->graphId() !== $graphId) {
                throw new RuntimeException("Adapter graphId mismatch for {$graphId}.");
            }

            foreach ($adapter->supportedDataElementIds() as $elementId) {
                $slice = $adapter->sliceIdForElement($elementId);
                if ($slice === null) {
                    continue;
                }
                $allowed = $adapter->supportedSliceIds();
                $tenantSlices = $adapter->supportedSliceIdsForTenant(0);
                if ($allowed !== [] && ! in_array($slice, $allowed, true)) {
                    throw new RuntimeException("Element {$elementId} maps to unknown slice {$slice} on {$graphId}.");
                }
                if ($allowed === [] && $tenantSlices === [] && $slice !== '') {
                    // Trend/forecast use dynamic slices only — skip static check.
                }
            }
        }
    }
}
