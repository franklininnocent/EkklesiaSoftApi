<?php

namespace Modules\Donations\Services\Reports;

use Illuminate\Http\Exceptions\HttpResponseException;
use Modules\Authentication\Models\User;
use Modules\Donations\Models\DonationReportExport;
use Modules\Donations\Services\DonationAuditService;
use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\DonationReportExportFormat;
use Modules\Donations\Support\Reports\ReportFilter;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DonationReportExportOrchestrator
{
    private const MAX_ACTIVE_EXPORTS_PER_TENANT = 10;

    private const MAX_ACTIVE_EXPORTS_PER_USER = 5;

    public function __construct(
        private readonly DonationAuditService $auditService,
        private readonly DonationReportAuthorization $authorization,
        private readonly DonationReportXlsxExportService $xlsxExportService,
        private readonly DonationReportPdfExportService $pdfExportService,
    ) {}

    public function queueExport(int $tenantId, User $user, ReportFilter $filter): DonationReportExport
    {
        $this->authorization->assertCanExport($user);
        $tenant = Tenant::query()->findOrFail($tenantId);
        $this->authorization->assertReportEntitlements($tenant, $filter->reportType);

        DonationReportCatalog::definition($filter->reportType);
        DonationReportCatalog::assertSupportedFilters($filter->reportType, $filter);

        $hash = $filter->hashForIdempotency($tenantId, (int) $user->id);
        $existing = DonationReportExport::query()
            ->forTenant($tenantId)
            ->where('filter_hash', $hash)
            ->where(function ($query): void {
                $query->whereIn('status', ['queued', 'processing'])
                    ->orWhere(function ($completed): void {
                        $completed->where('status', 'completed')
                            ->where(function ($expiry): void {
                                $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now());
                            });
                    });
            })
            ->orderByDesc('created_at')
            ->first();

        if ($existing !== null) {
            if (in_array($existing->status, ['queued', 'processing'], true)) {
                return $this->processExportRecord($existing->id) ?? $existing;
            }

            return $existing;
        }

        $this->assertWithinConcurrencyLimits($tenantId, (int) $user->id);

        $snapshot = $filter->toSnapshot();
        $expiresDays = (int) config('donations.reports.export_ttl_days', 7);

        $exportFormat = $filter->exportFormat();

        $export = DonationReportExport::create([
            'tenant_id' => $tenantId,
            'report_type' => $filter->reportType,
            'export_format' => $exportFormat,
            'filters' => $snapshot,
            'filter_hash' => $hash,
            'status' => 'queued',
            'requested_by' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'expires_at' => now()->addDays($expiresDays),
        ]);

        $this->auditService->log($tenantId, 'report.exported', 'report_export', $export->id, null, [
            'report_type' => $filter->reportType,
            'export_format' => $exportFormat,
            'filters' => $snapshot,
        ]);

        return $this->processExportRecord($export->id) ?? $export;
    }

    public function processQueuedForTenant(int $tenantId, int $limit = 10): void
    {
        $ids = DonationReportExport::query()
            ->forTenant($tenantId)
            ->where('status', 'queued')
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            $this->processExportRecord((string) $id);
        }
    }

    public function processExportRecord(string $exportId): ?DonationReportExport
    {
        $export = DonationReportExport::query()->find($exportId);
        if ($export === null) {
            return null;
        }

        $export->status = 'processing';
        $export->started_at = now();
        $export->save();

        $tenantId = (int) $export->tenant_id;
        $filters = is_array($export->filters) ? $export->filters : [];
        $reportType = (string) $export->report_type;
        $filter = ReportFilter::fromValidated($reportType, $filters);

        $directory = storage_path('app/reports');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $exportFormat = DonationReportExportFormat::normalize(
            is_string($export->export_format) ? $export->export_format : null,
        );
        $extension = DonationReportExportFormat::extension($exportFormat);
        $filename = sprintf('donation_%s_%d_%s.%s', $reportType, $tenantId, now()->format('Ymd_His'), $extension);
        $absolutePath = $directory.'/'.$filename;
        $relativePath = 'reports/'.$filename;

        try {
            $rowCount = match ($exportFormat) {
                DonationReportExportFormat::XLSX => $this->xlsxExportService->write($tenantId, $filter, $absolutePath),
                DonationReportExportFormat::PDF => $this->pdfExportService->write($tenantId, $filter, $absolutePath),
                default => $this->writeCsvExport($tenantId, $filter, $absolutePath),
            };

            $export->status = 'completed';
            $export->file_path = $relativePath;
            $export->file_size = is_file($absolutePath) ? filesize($absolutePath) : null;
            $export->row_count = $rowCount;
            $export->completed_at = now();
            $export->error_message = null;
            $export->save();

            $this->auditService->log($tenantId, 'report.export_completed', 'report_export', $export->id, null, [
                'row_count' => $rowCount,
            ]);
        } catch (\Throwable $exception) {
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
            $export->status = 'failed';
            $export->error_message = $exception->getMessage();
            $export->completed_at = now();
            $export->save();

            $this->auditService->log($tenantId, 'report.export_failed', 'report_export', $export->id, null, [
                'error' => $exception->getMessage(),
            ]);
        }

        return $export;
    }

    /**
     * @return StreamedResponse
     */
    public function downloadExport(string $exportId, User $user)
    {
        $this->authorization->assertCanExport($user);
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

        $export = DonationReportExport::query()
            ->forTenant($tenantId)
            ->whereKey($exportId)
            ->first();

        if ($export === null || $export->status !== 'completed') {
            abort(Response::HTTP_NOT_FOUND);
        }

        if ($export->status === 'expired' || ($export->expires_at !== null && $export->expires_at->isPast())) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $relative = (string) $export->file_path;
        if ($relative === '' || str_contains($relative, '..')) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $absolute = storage_path('app/'.$relative);
        $reportsRoot = realpath(storage_path('app/reports'));
        $fileReal = realpath($absolute);
        if ($reportsRoot === false || $fileReal === false || ! str_starts_with($fileReal, $reportsRoot) || ! is_file($fileReal)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $this->auditService->log($tenantId, 'report.downloaded', 'report_export', $export->id, null, [
            'report_type' => $export->report_type,
        ]);

        $downloadName = basename($fileReal);

        $format = DonationReportExportFormat::normalize(
            is_string($export->export_format) ? $export->export_format : null,
        );

        return response()->streamDownload(function () use ($fileReal): void {
            $stream = fopen($fileReal, 'rb');
            if ($stream === false) {
                return;
            }
            fpassthru($stream);
            fclose($stream);
        }, $downloadName, [
            'Content-Type' => DonationReportExportFormat::mimeType($format),
        ]);
    }

    private function writeCsvExport(int $tenantId, ReportFilter $filter, string $absolutePath): int
    {
        $handle = fopen($absolutePath, 'w');
        if ($handle === false) {
            throw new \RuntimeException('Could not create export file.');
        }

        try {
            return app(DonationModuleReportService::class)->streamExportCsv($tenantId, $filter, $handle);
        } finally {
            fclose($handle);
        }
    }

    private function assertWithinConcurrencyLimits(int $tenantId, int $userId): void
    {
        $tenantActive = DonationReportExport::query()
            ->forTenant($tenantId)
            ->whereIn('status', ['queued', 'processing'])
            ->count();

        if ($tenantActive >= self::MAX_ACTIVE_EXPORTS_PER_TENANT) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Too many report exports are already running for this parish. Please wait for one to finish.',
            ], Response::HTTP_TOO_MANY_REQUESTS));
        }

        $userActive = DonationReportExport::query()
            ->forTenant($tenantId)
            ->where('requested_by', $userId)
            ->whereIn('status', ['queued', 'processing'])
            ->count();

        if ($userActive >= self::MAX_ACTIVE_EXPORTS_PER_USER) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'You already have report exports in progress. Please wait for one to finish.',
            ], Response::HTTP_TOO_MANY_REQUESTS));
        }
    }
}
