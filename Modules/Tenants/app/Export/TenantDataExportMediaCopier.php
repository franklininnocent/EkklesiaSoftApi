<?php

namespace Modules\Tenants\Export;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\ChurchLeadership;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\Tenant;

/**
 * Copies tenant-owned photos/logos into the export working directory from DB path refs only.
 * Never walks storage trees; never copies ecclesiastical/pope assets.
 */
class TenantDataExportMediaCopier
{
    private const PUBLIC_DISK = 'public';

    private const PATRON_THUMB_SIZES = ['128x128', '300x300'];

    /**
     * @return array{files: list<string>, warnings: list<string>, copied: int, skipped: int}
     */
    public function copy(int $tenantId, TenantExportStorage $storage): array
    {
        $candidates = $this->collectCandidates($tenantId);
        $files = [];
        $warnings = [];
        $copied = 0;
        $skipped = 0;
        $seen = [];

        foreach ($candidates as $candidate) {
            $relative = $this->normalizeRelativePath($candidate['path']);
            if ($relative === null) {
                $skipped++;
                $warnings[] = $this->warning($candidate['source'], 'skipped non-local or invalid path');

                continue;
            }

            if (! $this->isAuthorizedForTenant($relative, $tenantId, $candidate['kind'])) {
                $skipped++;
                $warnings[] = $this->warning($candidate['source'], 'skipped unauthorized path');
                Log::warning('Tenant export media skipped unauthorized path', [
                    'tenant_id' => $tenantId,
                    'source' => $candidate['source'],
                    'path' => $relative,
                ]);

                continue;
            }

            if (isset($seen[$relative])) {
                continue;
            }
            $seen[$relative] = true;

            if (! Storage::disk(self::PUBLIC_DISK)->exists($relative)) {
                $skipped++;
                $warnings[] = $this->warning($candidate['source'], 'file missing on disk');

                continue;
            }

            $zipPath = 'documents/'.$relative;
            if ($this->copyToWorking($storage, $relative, $zipPath)) {
                $files[] = $zipPath;
                $copied++;
            } else {
                $skipped++;
                $warnings[] = $this->warning($candidate['source'], 'could not copy file');
            }
        }

        return [
            'files' => $files,
            'warnings' => $warnings,
            'copied' => $copied,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return list<array{path: string, source: string, kind: string}>
     */
    private function collectCandidates(int $tenantId): array
    {
        $candidates = [];

        $tenant = Tenant::query()->find($tenantId);
        if ($tenant && is_string($tenant->logo_url) && $tenant->logo_url !== '') {
            $candidates[] = [
                'path' => $tenant->logo_url,
                'source' => 'tenants.logo_url',
                'kind' => 'tenant_logo',
            ];
        }

        Family::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q): void {
                $q->whereNotNull('profile_image_url')
                    ->orWhereNotNull('head_profile_image_url');
            })
            ->orderBy('id')
            ->select(['id', 'profile_image_url', 'head_profile_image_url'])
            ->chunkById(200, function ($families) use (&$candidates): void {
                foreach ($families as $family) {
                    if (is_string($family->profile_image_url) && $family->profile_image_url !== '') {
                        $candidates[] = [
                            'path' => $family->profile_image_url,
                            'source' => "families.{$family->id}.profile_image_url",
                            'kind' => 'family_image',
                        ];
                    }
                    if (is_string($family->head_profile_image_url) && $family->head_profile_image_url !== '') {
                        $candidates[] = [
                            'path' => $family->head_profile_image_url,
                            'source' => "families.{$family->id}.head_profile_image_url",
                            'kind' => 'family_image',
                        ];
                    }
                }
            });

        if (Schema::hasColumn('church_profiles', 'patron_image_path')) {
            $profiles = ChurchProfile::query()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('patron_image_path')
                ->get(['id', 'patron_image_path']);

            foreach ($profiles as $profile) {
                if (! is_string($profile->patron_image_path) || $profile->patron_image_path === '') {
                    continue;
                }
                $candidates[] = [
                    'path' => $profile->patron_image_path,
                    'source' => "church_profiles.{$profile->id}.patron_image_path",
                    'kind' => 'patron_image',
                ];
                foreach ($this->patronThumbPaths($profile->patron_image_path) as $thumbPath) {
                    $candidates[] = [
                        'path' => $thumbPath,
                        'source' => "church_profiles.{$profile->id}.patron_image_thumb",
                        'kind' => 'patron_image',
                    ];
                }
            }
        }

        if (Schema::hasColumn('church_leadership', 'photo_url')) {
            ChurchLeadership::query()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('photo_url')
                ->orderBy('id')
                ->select(['id', 'photo_url'])
                ->chunkById(200, function ($leaders) use (&$candidates): void {
                    foreach ($leaders as $leader) {
                        if (! is_string($leader->photo_url) || $leader->photo_url === '') {
                            continue;
                        }
                        $candidates[] = [
                            'path' => $leader->photo_url,
                            'source' => "church_leadership.{$leader->id}.photo_url",
                            'kind' => 'leadership_photo',
                        ];
                    }
                });
        }

        return $candidates;
    }

    /**
     * @return list<string>
     */
    private function patronThumbPaths(string $path): array
    {
        $normalized = $this->normalizeRelativePath($path);
        if ($normalized === null) {
            return [];
        }

        $info = pathinfo($normalized);
        $directory = $info['dirname'] ?? '';
        $filename = $info['filename'] ?? '';
        $extension = $info['extension'] ?? '';
        if ($directory === '' || $filename === '' || $extension === '') {
            return [];
        }

        $thumbs = [];
        foreach (self::PATRON_THUMB_SIZES as $size) {
            $thumbs[] = $directory.'/'.$filename.'_'.$size.'.'.$extension;
        }

        return $thumbs;
    }

    private function normalizeRelativePath(string $raw): ?string
    {
        $path = trim(str_replace('\\', '/', $raw));
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '..')) {
            return null;
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return null;
        }

        if (str_starts_with($path, '/')) {
            if (preg_match('#^/storage/(.+)$#', $path, $matches) === 1) {
                $path = $matches[1];
            } else {
                return null;
            }
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl !== '' && str_starts_with($path, $appUrl.'/storage/')) {
            $path = substr($path, strlen($appUrl.'/storage/'));
        }

        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        // Never export ecclesiastical / pope media.
        if (preg_match('#(^|/)(popes?|ecclesiastical)(/|$)#i', $path) === 1) {
            return null;
        }

        return $path;
    }

    private function isAuthorizedForTenant(string $path, int $tenantId, string $kind): bool
    {
        if (preg_match('#(^|/)(popes?|ecclesiastical)(/|$)#i', $path) === 1) {
            return false;
        }

        return match ($kind) {
            'tenant_logo' => (bool) preg_match("#^tenants/{$tenantId}/#", $path)
                || (str_starts_with($path, 'tenants/logos/') && ! preg_match('#^tenants/\d+/#', $path)),
            'family_image' => (bool) preg_match("#^families/{$tenantId}/#", $path),
            'patron_image' => str_contains($path, "tenants/{$tenantId}/patron"),
            'leadership_photo' => (bool) preg_match("#^tenants/{$tenantId}/leadership/#", $path)
                || (
                    // Tolerate historical upload bug that stored a literal PHP expression in the directory.
                    str_contains($path, 'tenants/{app(')
                    && str_contains($path, '/leadership/')
                    && preg_match("#leader_t{$tenantId}_#", $path)
                ),
            default => false,
        };
    }

    private function copyToWorking(TenantExportStorage $storage, string $publicRelative, string $zipRelative): bool
    {
        try {
            $contents = Storage::disk(self::PUBLIC_DISK)->get($publicRelative);
            if ($contents === null) {
                return false;
            }

            $targetRelative = $storage->workingRelativePath().'/'.$zipRelative;
            $directory = dirname($targetRelative);
            if (! $storage->disk()->exists($directory)) {
                $storage->disk()->makeDirectory($directory);
            }

            return $storage->disk()->put($targetRelative, $contents);
        } catch (\Throwable $e) {
            Log::warning('Tenant export media copy failed', [
                'path' => $publicRelative,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function warning(string $source, string $message): string
    {
        return "media:{$source}: {$message}";
    }
}
