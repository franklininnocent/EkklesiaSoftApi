<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\Donations\Http\Requests\DashboardDateRangeRequest;
use Modules\Donations\Http\Requests\DonationReportFilterRequest;
use Modules\Donations\Http\Requests\ReportDrillDownRequest;
use Modules\Donations\Models\DonationReportExport;
use Modules\Donations\Services\DashboardPersonaService;
use Modules\Donations\Services\DonationReportService;
use Modules\Donations\Services\ExecutiveBoardPackPrintService;
use Modules\Donations\Services\ReportDrillDownService;
use Modules\Donations\Services\Reports\DonationModuleReportService;
use Modules\Donations\Services\Reports\DonationOperationalReportPrintService;
use Modules\Donations\Services\Reports\DonationReportAuthorization;
use Modules\Donations\Services\Reports\DonationReportExportOrchestrator;
use Modules\Donations\Services\StewardshipReportPrintService;
use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\DonationReportExportFormat;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;

class DonationReportsController extends Controller
{
    public function __construct(
        private readonly DonationReportService $reportService,
        private readonly StewardshipReportPrintService $stewardshipPrintService,
        private readonly ExecutiveBoardPackPrintService $executiveBoardPackPrintService,
        private readonly ReportDrillDownService $drillDownService,
        private readonly DonationModuleReportService $moduleReportService,
        private readonly DonationReportExportOrchestrator $exportOrchestrator,
        private readonly DonationReportAuthorization $reportAuthorization,
        private readonly DonationOperationalReportPrintService $operationalPrintService,
        private readonly DashboardPersonaService $personaService,
    ) {}

    public function operationalPrint(DonationReportFilterRequest $request)
    {
        $user = $this->actor();
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $tenant = Tenant::query()->findOrFail($tenantId);
        $this->reportAuthorization->assertCanPreview($user);
        $filter = $request->resolvedFilter();
        $this->reportAuthorization->assertReportEntitlements($tenant, $filter->reportType);

        $html = $this->operationalPrintService->renderHtml($tenantId, $filter);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public function catalog(): JsonResponse
    {
        $user = $this->actor();
        $this->reportAuthorization->assertCanPreview($user);

        $persona = $this->personaService->resolve($user);
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $tenant = Tenant::query()->find($tenantId);
        $tier = (string) ($tenant?->tenant_tier ?? '');

        return response()->json([
            'success' => true,
            'data' => DonationReportCatalog::catalogForApi($tier),
            'meta' => [
                'default_report' => $persona['default_report'] ?? 'payments',
            ],
        ]);
    }

    public function preview(DonationReportFilterRequest $request): JsonResponse
    {
        $user = $this->actor();
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $tenant = Tenant::query()->findOrFail($tenantId);
        $this->reportAuthorization->assertCanPreview($user);
        $filter = $request->resolvedFilter();
        $this->reportAuthorization->assertReportEntitlements($tenant, $filter->reportType);

        return response()->json([
            'success' => true,
            'data' => $this->moduleReportService->preview($tenantId, $filter),
        ]);
    }

    public function export(DonationReportFilterRequest $request): JsonResponse
    {
        $user = $this->actor();
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $filter = $request->resolvedFilter();

        $report = $this->exportOrchestrator->queueExport($tenantId, $user, $filter);

        return response()->json([
            'success' => true,
            'message' => 'Report export queued.',
            'data' => $report,
        ], 201);
    }

    public function exports(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $this->exportOrchestrator->processQueuedForTenant($tenantId);

        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        return response()->json([
            'success' => true,
            'data' => $this->exportHistoryQuery($request, $tenantId)
                ->paginate($perPage)
                ->through(fn (DonationReportExport $export) => $this->serializeExport($export)),
        ]);
    }

    public function downloadExport(string $id)
    {
        return $this->exportOrchestrator->downloadExport($id, $this->actor());
    }

    public function executiveSummary(DashboardDateRangeRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->reportService->buildExecutiveNarrative(
                app(TenantContext::class)->requireEffectiveTenantId(),
                $request->resolvedRange(),
                $request->resolvedBccFilter(),
                $request->resolvedProjectFilter()
            ),
        ]);
    }

    public function drillDown(ReportDrillDownRequest $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

        return response()->json([
            'success' => true,
            'data' => $this->drillDownService->build($tenantId, $request->validated()),
        ]);
    }

    public function parishComparison(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->reportService->buildParishComparisonReport(app(TenantContext::class)->requireEffectiveTenantId()),
        ]);
    }

    public function stewardshipPrint()
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $payload = $this->stewardshipPrintService->buildPayload($tenantId);
        $html = $this->stewardshipPrintService->renderHtml($payload);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public function executiveBoardPrint()
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $payload = $this->executiveBoardPackPrintService->buildPayload($tenantId);
        $html = $this->executiveBoardPackPrintService->renderHtml($payload);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    private function exportHistoryQuery(Request $request, int $tenantId)
    {
        $query = DonationReportExport::forTenant($tenantId)
            ->with('requester:id,name');

        $report = trim((string) $request->query('report', ''));
        if ($report !== '' && in_array($report, DonationReportCatalog::allTypes(), true)) {
            $query->where('report_type', $report);
        }

        $status = trim((string) $request->query('status', ''));
        if ($status === 'expired') {
            $query->where(function ($inner): void {
                $inner->where('status', 'expired')
                    ->orWhere(function ($completed): void {
                        $completed->where('status', 'completed')
                            ->whereNotNull('expires_at')
                            ->where('expires_at', '<=', now());
                    });
            });
        } elseif ($status === 'completed') {
            $query->where('status', 'completed')
                ->where(function ($inner): void {
                    $inner->whereNull('expires_at')->orWhere('expires_at', '>', now());
                });
        } elseif (in_array($status, ['queued', 'processing', 'failed'], true)) {
            $query->where('status', $status);
        }

        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) === 1) {
            $query->whereDate('donation_report_exports.created_at', '>=', $dateFrom);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) === 1) {
            $query->whereDate('donation_report_exports.created_at', '<=', $dateTo);
        }

        $search = trim((string) $request->query('search', ''));
        if (mb_strlen($search) >= 2) {
            $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $term = '%'.addcslashes($search, '%_\\').'%';
            $matchingTypes = [];
            foreach (DonationReportCatalog::definitions() as $key => $definition) {
                if (mb_stripos($definition['label'], $search) !== false || mb_stripos($key, $search) !== false) {
                    $matchingTypes[] = $key;
                }
            }
            $query->where(function ($inner) use ($like, $term, $matchingTypes): void {
                $inner->whereHas('requester', function ($user) use ($like, $term): void {
                    $user->where('name', $like, $term);
                });
                if ($matchingTypes !== []) {
                    $inner->orWhereIn('report_type', $matchingTypes);
                }
                $inner->orWhere('report_type', $like, $term);
            });
        }

        $this->applyExportHistorySort($query, $request);

        return $query;
    }

    private function applyExportHistorySort($query, Request $request): void
    {
        $sort = trim((string) $request->query('sort', 'created_at'));
        $direction = strtolower(trim((string) $request->query('direction', 'desc'))) === 'asc' ? 'asc' : 'desc';
        $allowed = ['report', 'requested_by', 'created_at', 'status', 'completed_at'];
        if (! in_array($sort, $allowed, true)) {
            $sort = 'created_at';
            $direction = 'desc';
        }

        if ($sort === 'report') {
            $cases = [];
            $bindings = [];
            foreach (DonationReportCatalog::definitions() as $key => $definition) {
                $cases[] = 'WHEN donation_report_exports.report_type = ? THEN ?';
                $bindings[] = $key;
                $bindings[] = $definition['label'];
            }
            $sql = 'CASE '.implode(' ', $cases).' ELSE donation_report_exports.report_type END';
            $query->orderByRaw($sql.' '.$direction, $bindings);
        } elseif ($sort === 'requested_by') {
            $query->leftJoin('users', 'users.id', '=', 'donation_report_exports.requested_by')
                ->select('donation_report_exports.*')
                ->orderByRaw('users.name '.$direction.' NULLS LAST');
        } elseif ($sort === 'status') {
            $query->orderBy('donation_report_exports.status', $direction);
        } elseif ($sort === 'completed_at') {
            $query->orderByRaw('donation_report_exports.completed_at '.$direction.' NULLS LAST');
        } else {
            $query->orderBy('donation_report_exports.created_at', $direction);
        }

        if ($sort !== 'created_at') {
            $query->orderByDesc('donation_report_exports.created_at');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeExport(DonationReportExport $export): array
    {
        return [
            'id' => $export->id,
            'report_type' => $export->report_type,
            'export_format' => $this->resolveExportFormat($export),
            'filters' => $export->filters,
            'status' => $export->status,
            'error_message' => $export->error_message,
            'file_size' => $export->file_size,
            'row_count' => $export->row_count,
            'requested_by' => $export->requested_by,
            'requested_by_name' => $export->requester?->name,
            'created_at' => $export->created_at,
            'completed_at' => $export->completed_at,
            'expires_at' => $export->expires_at,
            'downloadable' => $export->status === 'completed'
                && ($export->expires_at === null || $export->expires_at->isFuture()),
        ];
    }

    private function resolveExportFormat(DonationReportExport $export): string
    {
        $filters = is_array($export->filters) ? $export->filters : [];
        if (isset($filters['export_format']) && is_string($filters['export_format']) && $filters['export_format'] !== '') {
            return DonationReportExportFormat::normalize($filters['export_format']);
        }

        if (is_string($export->export_format) && $export->export_format !== '') {
            return DonationReportExportFormat::normalize($export->export_format);
        }

        $path = strtolower((string) $export->file_path);
        if (str_ends_with($path, '.xlsx')) {
            return DonationReportExportFormat::XLSX;
        }
        if (str_ends_with($path, '.pdf')) {
            return DonationReportExportFormat::PDF;
        }

        return DonationReportExportFormat::CSV;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
