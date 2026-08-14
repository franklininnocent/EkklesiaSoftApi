<?php

namespace Modules\Tenants\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Authentication\Models\User;
use Modules\Tenants\Export\TenantDataExportContributorRegistry;
use Modules\Tenants\Export\TenantDataExportMediaCopier;
use Modules\Tenants\Export\TenantExportStorage;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Models\TenantDataExport;
use Modules\Tenants\Support\TenantContext;
use RuntimeException;
use Throwable;
use ZipArchive;

class TenantDataExportOrchestrator
{
    public function __construct(
        private readonly TenantDataExportContributorRegistry $registry,
        private readonly TenantDataExportAuditService $auditService,
        private readonly TenantDataExportMediaCopier $mediaCopier
    ) {}

    public function process(string $exportId): TenantDataExport
    {
        $export = TenantDataExport::find($exportId);
        if (! $export) {
            throw new RuntimeException('Export record not found.');
        }

        if ($export->status === TenantDataExport::STATUS_CANCELLED) {
            return $export;
        }

        if ($export->status === TenantDataExport::STATUS_COMPLETED) {
            return $export;
        }

        $tenantId = (int) $export->tenant_id;
        $this->bindTenantContext($export);

        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant || (int) $tenant->active !== 1 || $tenant->trashed()) {
            return $this->fail($export, 'This parish is inactive or unavailable. Export cannot continue.');
        }

        $export->status = TenantDataExport::STATUS_PROCESSING;
        $export->started_at = now();
        $export->error_message = null;
        $export->save();

        $this->auditService->logExportEvent(
            $tenantId,
            (string) $export->id,
            TenantDataExportAuditService::EVENT_STARTED,
            null,
            ['modules' => $export->modules]
        );

        $storage = new TenantExportStorage;
        $modules = is_array($export->modules) ? $export->modules : [];
        $chunkSize = max(50, (int) config('tenants.export.chunk_size', 500));

        try {
            $storage->createWorkingDirectory($tenantId, (string) $export->id);

            $recordCounts = [];
            $warnings = [];
            $completedModules = 0;
            $totalModules = max(1, count($modules));

            foreach ($modules as $moduleKey) {
                $export->refresh();
                if ($export->status === TenantDataExport::STATUS_CANCELLED) {
                    $storage->deleteWorkingDirectory();

                    return $export;
                }

                $this->updateModuleProgress($export, $moduleKey, 'processing', 0, $completedModules, $totalModules);

                $contributor = $this->registry->get($moduleKey);
                $result = $contributor->export(
                    $tenantId,
                    $storage,
                    $chunkSize,
                    function (string $relativeFile, int $records) use ($export, $moduleKey, $completedModules, $totalModules): void {
                        $this->updateModuleProgress($export, $moduleKey, 'processing', $records, $completedModules, $totalModules);
                    }
                );

                $files = $result['files'] ?? [];
                foreach ($files as $path => $count) {
                    $recordCounts[$path] = (int) $count;
                }
                if (! empty($result['warnings']) && is_array($result['warnings'])) {
                    $warnings = array_merge($warnings, $result['warnings']);
                }

                $moduleRecords = array_sum(array_map('intval', $files));
                $completedModules++;
                $this->updateModuleProgress($export, $moduleKey, 'completed', $moduleRecords, $completedModules, $totalModules);
            }

            $includeMedia = (bool) (($export->options['include_media'] ?? false));
            $mediaFiles = [];
            if ($includeMedia) {
                $mediaResult = $this->mediaCopier->copy($tenantId, $storage);
                $mediaFiles = $mediaResult['files'] ?? [];
                if (! empty($mediaResult['warnings']) && is_array($mediaResult['warnings'])) {
                    $warnings = array_merge($warnings, $mediaResult['warnings']);
                }
                $recordCounts['documents'] = count($mediaFiles);
            }

            $manifest = [
                'export_version' => 1,
                'export_id' => (string) $export->id,
                'tenant_id' => $tenantId,
                'tenant_name' => $tenant->name,
                'started_at' => $export->started_at?->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
                'modules' => $modules,
                'format' => 'csv',
                'include_media' => $includeMedia,
                'record_counts' => $recordCounts,
                'files' => array_values(array_unique(array_merge(
                    array_values(array_filter(array_keys($recordCounts), static fn ($key) => $key !== 'documents')),
                    $mediaFiles
                ))),
                'media_files' => $mediaFiles,
                'application' => config('app.name'),
                'application_version' => config('app.version', env('APP_VERSION', 'unknown')),
                'warnings' => $warnings,
                'consistency' => 'running_window_read_not_pitr_snapshot',
            ];
            $storage->writeManifest($manifest);

            $zipRelative = $storage->finalZipRelativePath($tenantId, (string) $export->id);
            $this->packageZip($storage, $zipRelative);

            $retentionDays = max(1, (int) config('tenants.export.retention_days', 7));
            $fileSize = $storage->fileSize($zipRelative);

            $export->status = TenantDataExport::STATUS_COMPLETED;
            $export->file_path = $zipRelative;
            $export->file_size = $fileSize;
            $export->record_counts = $recordCounts;
            $export->completed_at = now();
            $export->expires_at = now()->addDays($retentionDays);
            $export->error_message = null;
            $export->progress = array_merge($export->progress ?? [], [
                'approx_percent' => 100,
            ]);
            $export->save();

            $storage->deleteWorkingDirectory();

            $this->auditService->logExportEvent(
                $tenantId,
                (string) $export->id,
                TenantDataExportAuditService::EVENT_COMPLETED,
                null,
                [
                    'file_size' => $fileSize,
                    'record_counts' => $recordCounts,
                    'expires_at' => $export->expires_at?->toIso8601String(),
                ]
            );

            return $export;
        } catch (Throwable $e) {
            Log::error('Tenant data export failed', [
                'export_id' => $export->id,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            try {
                $storage->deleteWorkingDirectory();
            } catch (Throwable) {
                // ignore cleanup failures
            }

            return $this->fail($export, 'Export failed while preparing your data. Please try again.');
        }
    }

    private function fail(TenantDataExport $export, string $safeMessage): TenantDataExport
    {
        $export->status = TenantDataExport::STATUS_FAILED;
        $export->error_message = $safeMessage;
        $export->completed_at = now();
        $export->save();

        $this->auditService->logExportEvent(
            (int) $export->tenant_id,
            (string) $export->id,
            TenantDataExportAuditService::EVENT_FAILED,
            null,
            ['message' => $safeMessage]
        );

        return $export;
    }

    private function bindTenantContext(TenantDataExport $export): void
    {
        $actorId = $export->requested_by ? (int) $export->requested_by : null;
        $homeTenantId = null;
        if ($actorId) {
            $user = User::query()->find($actorId);
            if ($user) {
                Auth::setUser($user);
                if ($user->tenant_id) {
                    $homeTenantId = (int) $user->tenant_id;
                }
            }
        }

        $context = new TenantContext(
            $actorId,
            $homeTenantId,
            (int) $export->tenant_id,
            null,
            null
        );
        app()->instance(TenantContext::class, $context);
    }

    private function updateModuleProgress(
        TenantDataExport $export,
        string $moduleKey,
        string $status,
        int $records,
        int $completedModules,
        int $totalModules
    ): void {
        $progress = is_array($export->progress) ? $export->progress : [];
        $modules = is_array($progress['modules'] ?? null) ? $progress['modules'] : [];
        $modules[$moduleKey] = [
            'status' => $status,
            'records' => $records,
        ];
        $progress['modules'] = $modules;
        $progress['approx_percent'] = (int) floor(($completedModules / max(1, $totalModules)) * 100);
        if ($status === 'processing' && $progress['approx_percent'] === 100) {
            $progress['approx_percent'] = 99;
        }

        $export->progress = $progress;
        $export->save();
    }

    private function packageZip(TenantExportStorage $storage, string $zipRelative): void
    {
        $workAbsolute = $storage->workingAbsolutePath();
        $zipAbsolute = $storage->absolutePath($zipRelative);
        $zipDir = dirname($zipAbsolute);
        if (! is_dir($zipDir) && ! mkdir($zipDir, 0775, true) && ! is_dir($zipDir)) {
            throw new RuntimeException('Unable to create export zip directory.');
        }

        if (is_file($zipAbsolute)) {
            unlink($zipAbsolute);
        }

        $zip = new ZipArchive;
        $opened = $zip->open($zipAbsolute, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException('Unable to create export archive.');
        }

        $this->addDirectoryToZip($zip, $workAbsolute, '');
        if ($zip->close() !== true) {
            throw new RuntimeException('Unable to finalize export archive.');
        }

        if (! is_file($zipAbsolute) || filesize($zipAbsolute) === 0) {
            throw new RuntimeException('Export archive was not written.');
        }

        $verify = new ZipArchive;
        $verified = $verify->open($zipAbsolute, ZipArchive::CHECKCONS);
        if ($verified !== true) {
            throw new RuntimeException('Export archive failed integrity check.');
        }
        $verify->close();
    }

    private function addDirectoryToZip(ZipArchive $zip, string $absoluteDir, string $prefix): void
    {
        $items = scandir($absoluteDir);
        if ($items === false) {
            throw new RuntimeException('Unable to read export working directory.');
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $absoluteDir.DIRECTORY_SEPARATOR.$item;
            $localName = ltrim(str_replace('\\', '/', $prefix.$item), '/');

            if (is_dir($path)) {
                // Prefer real files over empty dir markers; still create the folder for empty trees.
                $children = array_values(array_diff(scandir($path) ?: [], ['.', '..']));
                if ($children === []) {
                    $zip->addEmptyDir($localName);
                }
                $this->addDirectoryToZip($zip, $path, $localName.'/');

                continue;
            }

            // Embed bytes immediately — addFile() defers reads until close() and is fragile.
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException("Unable to read {$localName} for export archive.");
            }
            if ($zip->addFromString($localName, $contents) !== true) {
                throw new RuntimeException("Unable to add {$localName} to export archive.");
            }
        }
    }
}
