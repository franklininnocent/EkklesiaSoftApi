<?php

namespace Modules\Donations\Services\ReportDrillDown;

use Illuminate\Support\Facades\Log;
use Modules\Donations\Support\MoneyMath;

/**
 * Post-build checks: log when list totals diverge from chart SSOT (no PII).
 */
class ReportDrillDownReconciliationAuditor
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $validated
     */
    public function audit(int $tenantId, array $payload, array $validated): void
    {
        $context = $payload['context'] ?? [];
        $summary = $payload['summary'] ?? [];
        if ($context === []) {
            return;
        }

        $filtered = $this->hasActiveFilters($validated);
        if ($filtered) {
            return;
        }

        $page = max(1, (int) ($validated['page'] ?? 1));
        if ($page !== 1) {
            return;
        }

        $valueKind = (string) ($context['value_kind'] ?? 'money');
        $recordKind = (string) ($context['record_kind'] ?? 'family');
        $graphId = (string) ($context['graph_id'] ?? '');
        $sliceId = (string) ($context['slice_id'] ?? '');

        if ($valueKind === 'money' && in_array($recordKind, ['payment', 'family'], true)) {
            $expected = (float) ($context['expected_amount'] ?? 0);
            $listed = (float) ($summary['amount_total'] ?? 0);
            if (! MoneyMath::equals($expected, $listed)) {
                Log::warning('report.drill_down.reconciliation_mismatch', [
                    'tenant_id' => $tenantId,
                    'graph_id' => $graphId,
                    'slice_id' => $sliceId,
                    'expected_amount' => $expected,
                    'summary_amount_total' => $listed,
                ]);
            }
        }

        if ($valueKind === 'count' || $recordKind === 'family' && ($context['graph_id'] ?? '') === 'family_participation') {
            $expectedCount = (int) ($context['expected_count'] ?? 0);
            $recordCount = (int) ($summary['record_count'] ?? $summary['family_count'] ?? 0);
            $pageTotal = (int) ($payload['data']['total'] ?? 0);
            if ($recordCount !== $pageTotal) {
                Log::warning('report.drill_down.reconciliation_mismatch', [
                    'tenant_id' => $tenantId,
                    'graph_id' => $graphId,
                    'slice_id' => $sliceId,
                    'expected_count' => $expectedCount,
                    'summary_record_count' => $recordCount,
                    'paginator_total' => $pageTotal,
                ]);
            }
            if ($expectedCount !== $pageTotal && ($context['value_kind'] ?? '') === 'count') {
                Log::warning('report.drill_down.reconciliation_mismatch', [
                    'tenant_id' => $tenantId,
                    'graph_id' => $graphId,
                    'slice_id' => $sliceId,
                    'expected_count' => $expectedCount,
                    'paginator_total' => $pageTotal,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function hasActiveFilters(array $validated): bool
    {
        if (! empty($validated['search'])) {
            return true;
        }
        $filters = $validated['filters'] ?? [];
        if (! is_array($filters)) {
            return false;
        }

        foreach ($filters as $value) {
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }
}
