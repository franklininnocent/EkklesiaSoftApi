<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Jobs\ProcessDonationExportJob;
use Modules\Donations\Models\DonationReportExport;
use Modules\Donations\Services\DonationAuditService;
use Modules\Donations\Services\DonationReportService;
use Modules\Donations\Services\ExecutiveBoardPackPrintService;
use Modules\Donations\Services\StewardshipReportPrintService;

class DonationReportsController extends Controller
{
    public function __construct(
        private readonly DonationAuditService $auditService,
        private readonly DonationReportService $reportService,
        private readonly StewardshipReportPrintService $stewardshipPrintService,
        private readonly ExecutiveBoardPackPrintService $executiveBoardPackPrintService
    )
    {
    }

    public function export(Request $request): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();

        $report = DonationReportExport::create([
            'tenant_id' => $tenantId,
            'report_type' => $request->input('report_type', 'payments'),
            'filters' => $request->input('filters', []),
            'status' => 'queued',
            'requested_by' => $userId,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        ProcessDonationExportJob::dispatch($report->id);

        $this->auditService->log($tenantId, 'report.exported', 'report_export', $report->id, null, $report->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Report export generated.',
            'data' => $report,
        ], 201);
    }

    public function exports(): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();

        return response()->json([
            'success' => true,
            'data' => DonationReportExport::forTenant($tenantId)->orderByDesc('created_at')->paginate(20),
        ]);
    }

    public function executiveSummary(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->reportService->buildExecutiveNarrative(app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId()),
        ]);
    }

    public function parishComparison(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->reportService->buildParishComparisonReport(app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId()),
        ]);
    }

    public function stewardshipPrint()
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $payload = $this->stewardshipPrintService->buildPayload($tenantId);
        $html = $this->stewardshipPrintService->renderHtml($payload);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public function executiveBoardPrint()
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $payload = $this->executiveBoardPackPrintService->buildPayload($tenantId);
        $html = $this->executiveBoardPackPrintService->renderHtml($payload);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

}
