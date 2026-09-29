<?php

namespace Modules\Donations\Contracts;

use Modules\Donations\Support\DashboardDateRange;

/**
 * @phpstan-type DrillDownPayload array<string, mixed>
 */
interface ReportDrillDownAdapter
{
    public function graphId(): string;

    /**
     * @return array<int, string>
     */
    public function supportedDataElementIds(): array;

    /**
     * @return array<int, string>
     */
    public function supportedSliceIds(): array;

    /**
     * @return array<int, string>
     */
    public function supportedDimensions(): array;

    /**
     * @return array<int, string>
     */
    public function supportedSorts(): array;

    /**
     * @return array<int, string>
     */
    public function supportedFilterKeys(): array;

    public function sliceIdForElement(string $dataElementId): ?string;

    /**
     * Dynamic slice allowlist (e.g. trend months). Empty = use supportedSliceIds().
     *
     * @return array<int, string>
     */
    public function supportedSliceIdsForTenant(int $tenantId, ?DashboardDateRange $range = null): array;

    /**
     * @param  array<string, mixed>  $validated
     * @return DrillDownPayload
     */
    public function build(int $tenantId, array $validated): array;
}
