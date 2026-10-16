<?php

namespace Modules\Tenants\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Tenants\Export\TenantDataExportContributorRegistry;
use Modules\Tenants\Jobs\ProcessTenantDataExportJob;
use Modules\Tenants\Models\TenantDataExport;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantDataExportService
{
    public function __construct(
        private readonly TenantDataExportContributorRegistry $registry,
        private readonly TenantDataExportAuditService $auditService
    ) {}

    /**
     * @return list<array{key: string, label: string, default_selected: bool, estimated_records: int}>
     */
    public function moduleCatalog(int $tenantId): array
    {
        return $this->registry->catalog($tenantId);
    }

    /**
     * @param  list<string>  $modules
     * @param  array{include_media?: bool, format?: string}  $options
     */
    public function requestExport(int $tenantId, array $modules, array $options = [], ?int $requestedBy = null): TenantDataExport
    {
        $modules = array_values(array_unique(array_filter($modules, fn ($m) => is_string($m) && $m !== '')));
        if ($modules === []) {
            throw new HttpException(422, 'Select at least one data module to export.');
        }

        foreach ($modules as $module) {
            if (! $this->registry->has($module)) {
                throw new HttpException(422, "Unknown export module: {$module}");
            }
        }

        $maxActive = (int) config('tenants.export.max_active_per_tenant', 1);
        $activeCount = TenantDataExport::forTenant($tenantId)
            ->whereIn('status', TenantDataExport::ACTIVE_STATUSES)
            ->count();

        if ($activeCount >= $maxActive) {
            throw new HttpException(409, 'An export is already in progress for this parish. Wait for it to finish or cancel the queued export.');
        }

        $userId = $requestedBy ?? Auth::id();
        $payloadOptions = [
            'include_media' => (bool) ($options['include_media'] ?? false),
            'format' => (string) ($options['format'] ?? 'csv'),
        ];

        $export = DB::transaction(function () use ($tenantId, $modules, $payloadOptions, $userId, $maxActive) {
            // Lock matching rows (not COUNT ... FOR UPDATE — PostgreSQL rejects aggregates with FOR UPDATE).
            $lockedActive = TenantDataExport::forTenant($tenantId)
                ->whereIn('status', TenantDataExport::ACTIVE_STATUSES)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->count();

            if ($lockedActive >= $maxActive) {
                throw new HttpException(409, 'An export is already in progress for this parish. Wait for it to finish or cancel the queued export.');
            }

            return TenantDataExport::create([
                'tenant_id' => $tenantId,
                'requested_by' => $userId,
                'modules' => $modules,
                'options' => $payloadOptions,
                'status' => TenantDataExport::STATUS_QUEUED,
                'progress' => $this->initialProgress($modules),
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
        });

        ProcessTenantDataExportJob::dispatch($tenantId, $userId, $export->id)
            ->onQueue((string) config('tenants.export.queue', 'tenant-exports'))
            ->afterResponse();

        $this->auditService->logExportEvent(
            $tenantId,
            (string) $export->id,
            TenantDataExportAuditService::EVENT_REQUESTED,
            null,
            [
                'modules' => $modules,
                'options' => $payloadOptions,
            ]
        );

        return $export->fresh(['requester:id,name,email']) ?? $export;
    }

    public function listExports(int $tenantId, int $perPage = 20): LengthAwarePaginator
    {
        return TenantDataExport::forTenant($tenantId)
            ->with(['requester:id,name,email'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function findForTenant(int $tenantId, string $exportId): TenantDataExport
    {
        $export = TenantDataExport::forTenant($tenantId)->with(['requester:id,name,email'])->find($exportId);
        if (! $export) {
            throw new HttpException(404, 'Export not found.');
        }

        return $export;
    }

    public function cancel(int $tenantId, string $exportId): TenantDataExport
    {
        $export = $this->findForTenant($tenantId, $exportId);

        if ($export->status !== TenantDataExport::STATUS_QUEUED) {
            throw new HttpException(409, 'Only queued exports can be cancelled.');
        }

        $before = ['status' => $export->status];
        $export->status = TenantDataExport::STATUS_CANCELLED;
        $export->completed_at = now();
        $export->error_message = null;
        $export->save();

        $this->auditService->logExportEvent(
            $tenantId,
            (string) $export->id,
            TenantDataExportAuditService::EVENT_CANCELLED,
            $before,
            ['status' => $export->status]
        );

        return $export->fresh(['requester:id,name,email']) ?? $export;
    }

    public function retry(int $tenantId, string $exportId): TenantDataExport
    {
        $export = $this->findForTenant($tenantId, $exportId);

        if ($export->status !== TenantDataExport::STATUS_FAILED) {
            throw new HttpException(409, 'Only failed exports can be retried.');
        }

        return $this->requestExport(
            $tenantId,
            is_array($export->modules) ? $export->modules : [],
            is_array($export->options) ? $export->options : [],
            $export->requested_by ? (int) $export->requested_by : Auth::id()
        );
    }

    public function markDownloaded(TenantDataExport $export): void
    {
        $export->download_count = (int) $export->download_count + 1;
        $export->save();

        $this->auditService->logExportEvent(
            (int) $export->tenant_id,
            (string) $export->id,
            TenantDataExportAuditService::EVENT_DOWNLOADED,
            null,
            ['download_count' => $export->download_count]
        );
    }

    /**
     * Present a safe API payload (no internal paths beyond relative file_path when completed).
     *
     * @return array<string, mixed>
     */
    public function present(TenantDataExport $export): array
    {
        $totalRecords = 0;
        if (is_array($export->record_counts)) {
            foreach ($export->record_counts as $count) {
                $totalRecords += (int) $count;
            }
        }

        return [
            'id' => $export->id,
            'tenant_id' => $export->tenant_id,
            'modules' => $export->modules,
            'options' => $export->options,
            'status' => $export->status,
            'progress' => $export->progress,
            'record_counts' => $export->record_counts,
            'record_count_total' => $totalRecords,
            'file_size' => $export->file_size,
            'error_message' => $export->error_message,
            'requested_by' => $export->requested_by,
            'requested_by_name' => $export->requester?->name,
            'started_at' => $export->started_at?->toIso8601String(),
            'completed_at' => $export->completed_at?->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
            'download_count' => $export->download_count,
            'downloadable' => $export->isDownloadable(),
            'created_at' => $export->created_at?->toIso8601String(),
            'updated_at' => $export->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>  $modules
     * @return array{modules: array<string, array{status: string, records: int}>, approx_percent: int}
     */
    private function initialProgress(array $modules): array
    {
        $moduleProgress = [];
        foreach ($modules as $module) {
            $moduleProgress[$module] = [
                'status' => 'pending',
                'records' => 0,
            ];
        }

        return [
            'modules' => $moduleProgress,
            'approx_percent' => 0,
        ];
    }
}
