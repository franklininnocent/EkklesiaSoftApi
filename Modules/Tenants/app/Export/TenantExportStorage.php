<?php

namespace Modules\Tenants\Export;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Modules\Tenants\Contracts\ExportWriter;
use Modules\Tenants\Contracts\ExportWriterFactory;
use Modules\Tenants\Export\Writers\CsvExportWriter;
use RuntimeException;

/**
 * Private-disk working directories and final ZIP paths for tenant data exports.
 * Disk is config-driven (default: local → storage/app/private) for later S3 swap.
 */
class TenantExportStorage implements ExportWriterFactory
{
    private ?string $workingAbsolutePath = null;

    private ?string $workingRelativePath = null;

    public function __construct(
        private readonly ?string $diskName = null,
        private readonly string $format = 'csv'
    ) {}

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName ?? (string) config('tenants.export.disk', 'local'));
    }

    public function diskName(): string
    {
        return $this->diskName ?? (string) config('tenants.export.disk', 'local');
    }

    /**
     * Create (or reset) a working directory for an export run.
     *
     * @return string Absolute filesystem path to the working directory
     */
    public function createWorkingDirectory(int $tenantId, string $exportId): string
    {
        $relative = $this->workingRelative($tenantId, $exportId);

        if ($this->disk()->exists($relative)) {
            $this->disk()->deleteDirectory($relative);
        }

        $this->disk()->makeDirectory($relative.'/data');
        $this->disk()->makeDirectory($relative.'/documents');

        $absolute = $this->absolutePath($relative);
        if (! is_dir($absolute) && ! mkdir($absolute, 0775, true) && ! is_dir($absolute)) {
            throw new RuntimeException('Unable to create export working directory.');
        }

        $this->workingRelativePath = $relative;
        $this->workingAbsolutePath = $absolute;

        return $absolute;
    }

    public function workingAbsolutePath(): string
    {
        if ($this->workingAbsolutePath === null) {
            throw new RuntimeException('Export working directory has not been created.');
        }

        return $this->workingAbsolutePath;
    }

    public function workingRelativePath(): string
    {
        if ($this->workingRelativePath === null) {
            throw new RuntimeException('Export working directory has not been created.');
        }

        return $this->workingRelativePath;
    }

    public function create(string $relativePath): ExportWriter
    {
        $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($normalized === '' || str_contains($normalized, '..')) {
            throw new RuntimeException('Invalid export relative path.');
        }

        $absolute = $this->workingAbsolutePath().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalized);

        return match ($this->format) {
            'csv' => new CsvExportWriter($absolute),
            default => throw new RuntimeException('Unsupported export writer format: '.$this->format),
        };
    }

    public function writeManifest(array $manifest): string
    {
        $relative = $this->workingRelativePath().'/manifest.json';
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Unable to encode export manifest.');
        }

        $this->disk()->put($relative, $json."\n");

        return $this->absolutePath($relative);
    }

    public function finalZipRelativePath(int $tenantId, string $exportId): string
    {
        return sprintf('exports/%d/%s/tenant-data-export-%s.zip', $tenantId, $exportId, $exportId);
    }

    public function absolutePath(string $relativePath): string
    {
        $disk = $this->disk();
        if (method_exists($disk, 'path')) {
            return $disk->path($relativePath);
        }

        return storage_path('app/private/'.ltrim($relativePath, '/'));
    }

    public function deleteWorkingDirectory(): void
    {
        if ($this->workingRelativePath === null) {
            return;
        }

        if ($this->disk()->exists($this->workingRelativePath)) {
            $this->disk()->deleteDirectory($this->workingRelativePath);
        }

        $this->workingRelativePath = null;
        $this->workingAbsolutePath = null;
    }

    public function deleteRelative(string $relativePath): void
    {
        $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($normalized === '' || str_contains($normalized, '..')) {
            return;
        }

        if ($this->disk()->exists($normalized)) {
            $this->disk()->delete($normalized);
        }
    }

    public function fileSize(string $relativePath): int
    {
        $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
        if (! $this->disk()->exists($normalized)) {
            return 0;
        }

        return (int) $this->disk()->size($normalized);
    }

    private function workingRelative(int $tenantId, string $exportId): string
    {
        return sprintf('exports/%d/%s/work', $tenantId, $exportId);
    }
}
