<?php

namespace Modules\Tenants\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Tenants\Services\Media\ImageMediaPolicy;
use Modules\Tenants\Services\Media\ImageMediaService;
use Modules\Tenants\Support\TenantPrivateStorage;

class PromotePublicImagesToPrivate extends Command
{
    protected $signature = 'media:promote-public-images-to-private {--dry-run : Report actions without writing files}';

    protected $description = 'Re-encode referenced public-disk images onto the private disk and update database keys.';

    public function handle(ImageMediaService $imageMediaService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $promoted = 0;
        $skipped = 0;

        $references = $this->collectReferences();

        foreach ($references as $reference) {
            $key = $reference['key'];
            if ($key === null || $key === '') {
                continue;
            }

            if (TenantPrivateStorage::exists($key)) {
                $skipped++;

                continue;
            }

            if (! Storage::disk('public')->exists($key)) {
                $this->warn("Missing public object for {$reference['table']}.{$reference['column']} id={$reference['id']}");
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("[dry-run] Would promote {$key}");
                $promoted++;

                continue;
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'promote');
            file_put_contents($tempPath, Storage::disk('public')->get($key));
            $uploadedFile = new \Illuminate\Http\UploadedFile($tempPath, basename($key), null, null, true);

            try {
                $result = $imageMediaService->store(
                    $uploadedFile,
                    (int) $reference['tenant_id'],
                    (string) $reference['category']
                );

                DB::table($reference['table'])
                    ->where($reference['id_column'], $reference['id'])
                    ->update([$reference['column'] => $result->storageKey]);

                $promoted++;
                $this->info("Promoted {$key} -> {$result->storageKey}");
            } catch (\Throwable $e) {
                $this->error("Failed to promote {$key}: {$e->getMessage()}");
            } finally {
                @unlink($tempPath);
            }
        }

        $this->info("Promoted {$promoted}, skipped {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * @return list<array{table: string, column: string, id_column: string, id: int|string, tenant_id: int, category: string, key: ?string}>
     */
    private function collectReferences(): array
    {
        $items = [];

        foreach (DB::table('users')->whereNotNull('profile_image_path')->get(['id', 'tenant_id', 'profile_image_path']) as $row) {
            $items[] = [
                'table' => 'users',
                'column' => 'profile_image_path',
                'id_column' => 'id',
                'id' => $row->id,
                'tenant_id' => (int) $row->tenant_id,
                'category' => ImageMediaPolicy::CATEGORY_USERS,
                'key' => $row->profile_image_path,
            ];
        }

        foreach (DB::table('tenants')->whereNotNull('logo_url')->get(['id', 'logo_url']) as $row) {
            $items[] = [
                'table' => 'tenants',
                'column' => 'logo_url',
                'id_column' => 'id',
                'id' => $row->id,
                'tenant_id' => (int) $row->id,
                'category' => ImageMediaPolicy::CATEGORY_LOGOS,
                'key' => $row->logo_url,
            ];
        }

        if (DB::getSchemaBuilder()->hasTable('families')) {
            foreach (DB::table('families')->whereNotNull('profile_image_url')->get(['id', 'tenant_id', 'profile_image_url']) as $row) {
                $items[] = [
                    'table' => 'families',
                    'column' => 'profile_image_url',
                    'id_column' => 'id',
                    'id' => $row->id,
                    'tenant_id' => (int) $row->tenant_id,
                    'category' => ImageMediaPolicy::CATEGORY_FAMILIES,
                    'key' => $row->profile_image_url,
                ];
            }

            foreach (DB::table('families')->whereNotNull('head_profile_image_url')->get(['id', 'tenant_id', 'head_profile_image_url']) as $row) {
                $items[] = [
                    'table' => 'families',
                    'column' => 'head_profile_image_url',
                    'id_column' => 'id',
                    'id' => $row->id,
                    'tenant_id' => (int) $row->tenant_id,
                    'category' => ImageMediaPolicy::CATEGORY_FAMILIES,
                    'key' => $row->head_profile_image_url,
                ];
            }
        }

        if (DB::getSchemaBuilder()->hasTable('bishop_management')) {
            foreach (DB::table('bishop_management')->whereNotNull('photo_path')->get(['id', 'photo_path']) as $row) {
                $items[] = [
                    'table' => 'bishop_management',
                    'column' => 'photo_path',
                    'id_column' => 'id',
                    'id' => $row->id,
                    'tenant_id' => 0,
                    'category' => ImageMediaPolicy::CATEGORY_BISHOPS,
                    'key' => $row->photo_path,
                ];
            }
        }

        return $items;
    }
}
