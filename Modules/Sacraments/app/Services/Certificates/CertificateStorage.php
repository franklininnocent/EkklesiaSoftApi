<?php

namespace Modules\Sacraments\Services\Certificates;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Modules\Tenants\Support\TenantPrivateStorage;
use RuntimeException;

class CertificateStorage
{
    public function disk(): Filesystem
    {
        return TenantPrivateStorage::disk((string) config('sacraments.certificates.disk', 'local'));
    }

    /**
     * @return array{storage_key:string, checksum:string, size_bytes:int, mime_type:string}
     */
    public function store(int $tenantId, int $sacramentId, int $version, string $pdfBytes): array
    {
        $filename = sprintf('v%d-%s.pdf', $version, Str::uuid()->toString());
        $key = TenantPrivateStorage::relativePath($tenantId, 'sacrament-certificates/'.$sacramentId, $filename);

        if (! TenantPrivateStorage::put($key, $pdfBytes, (string) config('sacraments.certificates.disk', 'local'))) {
            throw new RuntimeException('Failed to store certificate PDF.');
        }

        return [
            'storage_key' => $key,
            'checksum' => hash('sha256', $pdfBytes),
            'size_bytes' => strlen($pdfBytes),
            'mime_type' => 'application/pdf',
        ];
    }

    public function storeHtml(int $tenantId, int $sacramentId, int $version, string $html): string
    {
        $filename = sprintf('v%d-%s.html', $version, Str::uuid()->toString());
        $key = TenantPrivateStorage::relativePath($tenantId, 'sacrament-certificates/'.$sacramentId, $filename);

        if (! TenantPrivateStorage::put($key, $html, (string) config('sacraments.certificates.disk', 'local'))) {
            throw new RuntimeException('Failed to store certificate HTML.');
        }

        return $key;
    }

    public function get(string $storageKey): string
    {
        $diskName = (string) config('sacraments.certificates.disk', 'local');

        if (TenantPrivateStorage::exists($storageKey, $diskName)) {
            return TenantPrivateStorage::get($storageKey, $diskName);
        }

        if ($this->disk()->exists($storageKey)) {
            return (string) $this->disk()->get($storageKey);
        }

        throw new RuntimeException('Certificate file not found.');
    }
}
