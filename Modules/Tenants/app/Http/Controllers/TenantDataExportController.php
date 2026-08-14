<?php

namespace Modules\Tenants\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Tenants\Export\TenantExportStorage;
use Modules\Tenants\Http\Requests\StoreTenantDataExportRequest;
use Modules\Tenants\Models\TenantDataExport;
use Modules\Tenants\Services\TenantDataExportService;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantDataExportController extends Controller
{
    public function __construct(
        private readonly TenantDataExportService $exportService
    ) {}

    public function modules(): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

        return response()->json([
            'success' => true,
            'data' => $this->exportService->moduleCatalog($tenantId),
        ]);
    }

    public function store(StoreTenantDataExportRequest $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $validated = $request->validated();

        $export = $this->exportService->requestExport(
            $tenantId,
            $validated['modules'],
            [
                'include_media' => (bool) ($validated['include_media'] ?? false),
                'format' => (string) ($validated['format'] ?? 'csv'),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Export queued. You can track progress in Export History.',
            'data' => $this->exportService->present($export),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $perPage = min(100, max(1, (int) $request->integer('per_page', 20)));
        $paginator = $this->exportService->listExports($tenantId, $perPage);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (TenantDataExport $export) => $this->exportService->present($export))
        );

        return response()->json([
            'success' => true,
            'data' => $paginator,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $export = $this->exportService->findForTenant($tenantId, $id);

        return response()->json([
            'success' => true,
            'data' => $this->exportService->present($export),
        ]);
    }

    public function download(string $id): StreamedResponse|JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $export = $this->exportService->findForTenant($tenantId, $id);

        if ($export->isExpired() && $export->status === TenantDataExport::STATUS_COMPLETED) {
            $export->status = TenantDataExport::STATUS_EXPIRED;
            $export->save();
        }

        if (! $export->isDownloadable()) {
            return response()->json([
                'success' => false,
                'message' => 'This export is not available for download.',
            ], 409);
        }

        $storage = new TenantExportStorage;
        $relative = (string) $export->file_path;
        if (! $storage->disk()->exists($relative)) {
            return response()->json([
                'success' => false,
                'message' => 'Export file is missing. Please start a new export.',
            ], 404);
        }

        $this->exportService->markDownloaded($export);

        $filename = 'tenant-data-export-'.$export->id.'.zip';
        $absolute = $storage->absolutePath($relative);
        $size = is_file($absolute) ? (int) filesize($absolute) : 0;

        // Discard accidental pre-response output (e.g. config whitespace) before binary stream.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        return $storage->disk()->download($relative, $filename, [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) $size,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function cancel(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $export = $this->exportService->cancel($tenantId, $id);

        return response()->json([
            'success' => true,
            'message' => 'Export cancelled.',
            'data' => $this->exportService->present($export),
        ]);
    }

    public function retry(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $export = $this->exportService->retry($tenantId, $id);

        return response()->json([
            'success' => true,
            'message' => 'Export queued again.',
            'data' => $this->exportService->present($export),
        ], 201);
    }
}
