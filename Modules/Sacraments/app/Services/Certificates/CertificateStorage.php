<?php

namespace Modules\Sacraments\Services\Certificates;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Private-disk storage for sacrament certificates (ADR-09 — no public URLs).
 */
class CertificateStorage
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('sacraments.certificates.disk', 'local'));
    }

    /**
     * @return array{storage_key:string, checksum:string, size_bytes:int, mime_type:string}
     */
    public function store(int $tenantId, int $sacramentId, int $version, string $pdfBytes): array
    {
        $key = sprintf(
            'sacrament-certificates/%d/%d/v%d-%s.pdf',
            $tenantId,
            $sacramentId,
            $version,
            Str::uuid()->toString()
        );

        if (! $this->disk()->put($key, $pdfBytes)) {
            throw new RuntimeException('Failed to store certificate PDF.');
        }

        return [
            'storage_key' => $key,
            'checksum' => hash('sha256', $pdfBytes),
            'size_bytes' => strlen($pdfBytes),
            'mime_type' => 'application/pdf',
        ];
    }

    public function get(string $storageKey): string
    {
        if (! $this->disk()->exists($storageKey)) {
            throw new RuntimeException('Certificate file not found.');
        }

        return (string) $this->disk()->get($storageKey);
    }
}
