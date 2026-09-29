<?php

namespace Modules\Tenants\LoadTesting;

final class LoadTestBenchmarkResult
{
    /**
     * @param  array<string, array{p50_ms: float, p95_ms: float, max_ms: float, min_ms: float, avg_ms: float, errors: int, query_p95: ?int}>  $scenarios
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly array $scenarios,
        public readonly bool $passed,
    ) {}
}
