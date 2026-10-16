<?php

namespace Modules\Donations\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Donations\Services\ReportDrillDown\ReportDrillDownReconciliationAuditor;
use Modules\Donations\Services\ReportDrillDown\ReportDrillDownRegistry;

class ReportDrillDownService
{
    public function __construct(
        private readonly ReportDrillDownRegistry $registry,
        private readonly ReportDrillDownReconciliationAuditor $auditor
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function build(int $tenantId, array $validated): array
    {
        $graphId = (string) $validated['graph_id'];

        if (! $this->registry->has($graphId)) {
            throw ValidationException::withMessages([
                'graph_id' => ['Graph drill-down is not available yet.'],
            ]);
        }

        try {
            $started = microtime(true);
            DB::enableQueryLog();

            try {
                $payload = $this->registry->get($graphId)->build($tenantId, $validated);
            } finally {
                $queryLog = DB::getQueryLog();
                DB::disableQueryLog();
            }

            $dbTimeMs = (int) round(array_sum(array_column($queryLog, 'time')));
            $durationMs = (int) round((microtime(true) - $started) * 1000);

            $this->auditor->audit($tenantId, $payload, $validated);

            Log::info('report.drill_down', [
                'graph_id' => $graphId,
                'slice_id' => $validated['slice_id'] ?? null,
                'tenant_id' => $tenantId,
                'duration_ms' => $durationMs,
                'db_time_ms' => $dbTimeMs,
                'record_count' => $payload['data']['total'] ?? null,
                'page' => $validated['page'] ?? 1,
                'filtered' => ! empty($validated['search']) || ! empty($validated['filters']),
            ]);

            return $payload;
        } catch (\InvalidArgumentException $exception) {
            $field = str_contains($exception->getMessage(), 'sort') ? 'sort' : 'slice_id';
            throw ValidationException::withMessages([
                $field => [$exception->getMessage()],
            ]);
        }
    }
}
