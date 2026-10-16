<?php

namespace Modules\Tenants\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Tenants\Services\Media\ImageMediaPaths;
use Modules\Tenants\Support\TenantPrivateStorage;

class CleanupOrphanMedia extends Command
{
    protected $signature = 'media:cleanup-orphans {--dry-run : Report orphan objects without deleting}';

    protected $description = 'Delete unreferenced private media objects (display + thumb pairs).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $referenced = $this->referencedKeys();
        $disk = TenantPrivateStorage::disk();
        $deleted = 0;

        foreach ($disk->allFiles() as $path) {
            if (! str_ends_with($path, '.webp')) {
                continue;
            }

            $displayKey = str_ends_with($path, '-thumb.webp')
                ? preg_replace('/-thumb\.webp$/', '.webp', $path)
                : $path;

            if ($displayKey === null || isset($referenced[$displayKey])) {
                continue;
            }

            if ($dryRun) {
                $this->line("[dry-run] Would delete orphan {$path}");
            } else {
                $disk->delete($path);
            }

            $deleted++;
        }

        $this->info(($dryRun ? 'Found' : 'Deleted')." {$deleted} orphan object(s).");

        return self::SUCCESS;
    }

    /**
     * @return array<string, true>
     */
    private function referencedKeys(): array
    {
        $keys = [];

        $add = static function (?string $key) use (&$keys): void {
            if ($key) {
                $keys[$key] = true;
                $keys[ImageMediaPaths::thumbKeyForDisplayKey($key)] = true;
            }
        };

        foreach (DB::table('users')->whereNotNull('profile_image_path')->pluck('profile_image_path') as $key) {
            $add($key);
        }

        foreach (DB::table('tenants')->whereNotNull('logo_url')->pluck('logo_url') as $key) {
            $add($key);
        }

        if (DB::getSchemaBuilder()->hasTable('families')) {
            foreach (DB::table('families')->whereNotNull('profile_image_url')->pluck('profile_image_url') as $key) {
                $add($key);
            }
            foreach (DB::table('families')->whereNotNull('head_profile_image_url')->pluck('head_profile_image_url') as $key) {
                $add($key);
            }
        }

        if (DB::getSchemaBuilder()->hasTable('bishop_management')) {
            foreach (DB::table('bishop_management')->whereNotNull('photo_path')->pluck('photo_path') as $key) {
                $add($key);
            }
        }

        return $keys;
    }
}
