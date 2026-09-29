<?php

namespace Modules\Tenants\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;

/**
 * Platform-admin storage and sign-in frequency for a single requested tenant.
 */
class TenantUsageService
{
    public const PREVIEW_LIMIT = 10;

    /**
     * @return array<string, mixed>
     */
    public function measureStorage(Tenant $tenant): array
    {
        $maxMb = max(0, (int) $tenant->max_storage_mb);

        try {
            $usedBytes = 0;
            $fileCount = 0;
            $prefix = 'tenants/'.$tenant->id;

            foreach ($this->storageDisks() as $diskName) {
                $disk = Storage::disk($diskName);
                $files = $disk->allFiles($prefix);

                foreach ($files as $path) {
                    if (! $this->isTenantOwnedPath((string) $path, (int) $tenant->id)) {
                        continue;
                    }

                    $usedBytes += (int) $disk->size($path);
                    $fileCount++;
                }
            }

            $usedMb = round($usedBytes / 1048576, 2);
            $remainingMb = round(max(0, ($maxMb * 1048576) - $usedBytes) / 1048576, 2);
            $percentUsed = $maxMb > 0
                ? round(min(100, ($usedBytes / ($maxMb * 1048576)) * 100), 1)
                : null;

            return [
                'available' => true,
                'error' => null,
                'used_bytes' => $usedBytes,
                'used_mb' => $usedMb,
                'max_storage_mb' => $maxMb,
                'remaining_mb' => $remainingMb,
                'percent_used' => $percentUsed,
                'file_count' => $fileCount,
            ];
        } catch (\Throwable $e) {
            Log::warning('Tenant storage measurement failed', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'available' => false,
                'error' => 'storage_unavailable',
                'used_bytes' => null,
                'used_mb' => null,
                'max_storage_mb' => $maxMb,
                'remaining_mb' => null,
                'percent_used' => null,
                'file_count' => null,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function userActivity(Tenant $tenant): array
    {
        $tenantId = (int) $tenant->id;

        $totalUsers = User::query()->where('tenant_id', $tenantId)->count();
        $activeUsers = User::query()->where('tenant_id', $tenantId)->where('active', 1)->count();
        $inactiveUsers = $totalUsers - $activeUsers;

        $roleDistribution = User::query()
            ->where('users.tenant_id', $tenantId)
            ->leftJoin('roles', 'roles.id', '=', 'users.role_id')
            ->selectRaw("COALESCE(roles.name, 'Unassigned') as role_name, COUNT(users.id) as user_count")
            ->groupBy('role_name')
            ->orderBy('role_name')
            ->get()
            ->map(fn ($row) => [
                'role' => (string) $row->role_name,
                'count' => (int) $row->user_count,
            ])
            ->values()
            ->all();

        $base = [
            'available' => true,
            'error' => null,
            'total_users' => $totalUsers,
            'active_users' => $activeUsers,
            'inactive_users' => $inactiveUsers,
            'seen_last_7d' => null,
            'seen_last_30d' => null,
            'frequent_users_30d' => null,
            'never_signed_in' => $totalUsers,
            'last_seen_at' => null,
            'role_distribution' => $roleDistribution,
            'preview' => [],
        ];

        if (! Schema::hasTable('oauth_access_tokens')) {
            $base['available'] = false;
            $base['error'] = 'activity_unavailable';
            $base['preview'] = $this->previewWithoutTokens($tenantId);

            return $base;
        }

        try {
            $since7 = Carbon::now()->subDays(7);
            $since30 = Carbon::now()->subDays(30);

            $tokenStats = DB::table('oauth_access_tokens')
                ->join('users', 'users.id', '=', 'oauth_access_tokens.user_id')
                ->where('users.tenant_id', $tenantId)
                ->whereNull('users.deleted_at')
                ->groupBy('users.id')
                ->selectRaw(
                    'users.id as user_id,
                    MAX(oauth_access_tokens.created_at) as last_seen_at,
                    SUM(CASE WHEN oauth_access_tokens.created_at >= ? THEN 1 ELSE 0 END) as sign_ins_30d,
                    SUM(CASE WHEN oauth_access_tokens.created_at >= ? THEN 1 ELSE 0 END) as sign_ins_7d',
                    [$since30, $since7]
                )
                ->get()
                ->keyBy('user_id');

            $seenLast7d = 0;
            $seenLast30d = 0;
            $frequentUsers30d = 0;
            $lastSeenAt = null;

            foreach ($tokenStats as $row) {
                $signIns30 = (int) $row->sign_ins_30d;
                $signIns7 = (int) $row->sign_ins_7d;
                if ($signIns7 > 0) {
                    $seenLast7d++;
                }
                if ($signIns30 > 0) {
                    $seenLast30d++;
                }
                if ($signIns30 >= 2) {
                    $frequentUsers30d++;
                }
                $seen = $row->last_seen_at ? Carbon::parse((string) $row->last_seen_at) : null;
                if ($seen && ($lastSeenAt === null || $seen->gt($lastSeenAt))) {
                    $lastSeenAt = $seen;
                }
            }

            $usersWithTokens = $tokenStats->count();

            $base['seen_last_7d'] = $seenLast7d;
            $base['seen_last_30d'] = $seenLast30d;
            $base['frequent_users_30d'] = $frequentUsers30d;
            $base['never_signed_in'] = max(0, $totalUsers - $usersWithTokens);
            $base['last_seen_at'] = $lastSeenAt?->toIso8601String();
            $base['preview'] = $this->previewWithTokens($tenantId, $tokenStats);

            return $base;
        } catch (\Throwable $e) {
            Log::warning('Tenant user activity measurement failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            $base['available'] = false;
            $base['error'] = 'activity_unavailable';
            $base['preview'] = $this->previewWithoutTokens($tenantId);

            return $base;
        }
    }

    /**
     * @return list<string>
     */
    private function storageDisks(): array
    {
        return array_values(array_unique(array_filter([
            'public',
            (string) config('tenants.storage.private_disk', 'local'),
            (string) config('tenants.export.disk', 'local'),
        ])));
    }

    private function isTenantOwnedPath(string $path, int $tenantId): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if ($path === '' || str_contains($path, '..')) {
            return false;
        }

        return str_starts_with($path, 'tenants/'.$tenantId.'/');
    }

    /**
     * @param  \Illuminate\Support\Collection<int|string, object>  $tokenStats
     * @return list<array<string, mixed>>
     */
    private function previewWithTokens(int $tenantId, $tokenStats): array
    {
        $users = User::query()
            ->where('tenant_id', $tenantId)
            ->with(['role:id,name'])
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'name', 'email', 'role_id', 'active', 'is_primary_admin', 'created_at', 'profile_image_path']);

        return $users
            ->map(function (User $user) use ($tokenStats) {
                $stats = $tokenStats->get($user->id);
                $lastSeen = $stats->last_seen_at ?? null;

                return $this->presentUserPreview(
                    $user,
                    $lastSeen ? (string) $lastSeen : null,
                    $stats ? (int) $stats->sign_ins_30d : 0
                );
            })
            ->sortByDesc(fn (array $row) => $row['last_seen_at'] ?? '')
            ->take(self::PREVIEW_LIMIT)
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function previewWithoutTokens(int $tenantId): array
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->with(['role:id,name'])
            ->orderByDesc('created_at')
            ->limit(self::PREVIEW_LIMIT)
            ->get(['id', 'name', 'email', 'role_id', 'active', 'is_primary_admin', 'created_at'])
            ->map(fn (User $user) => $this->presentUserPreview($user, null, null))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentUserPreview(User $user, ?string $lastSeenAt, ?int $signIns30d): array
    {
        $iso = null;
        if ($lastSeenAt) {
            try {
                $iso = Carbon::parse($lastSeenAt)->toIso8601String();
            } catch (\Throwable) {
                $iso = null;
            }
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'profile_image_full_url' => $user->profile_image_full_url,
            'role' => $user->role?->name,
            'status' => (int) $user->active === 1 ? 'active' : 'inactive',
            'is_primary_admin' => (bool) $user->is_primary_admin,
            'last_seen_at' => $iso,
            'sign_ins_30d' => $signIns30d,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
