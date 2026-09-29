<?php

namespace Modules\Tenants\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Tenants\Support\TenantRlsManager;

class PlatformHealthService
{
    /**
     * @return array{
     *     status: string,
     *     checks: array<string, array{status: string, latency_ms?: float, message?: string}>,
     *     flags: array<string, bool>,
     *     version: string,
     * }
     */
    public function snapshot(): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'queue' => $this->checkQueue(),
            'storage' => $this->checkStorage(),
        ];

        $status = collect($checks)->contains(fn (array $check) => $check['status'] === 'fail')
            ? 'fail'
            : (collect($checks)->contains(fn (array $check) => $check['status'] === 'degraded') ? 'degraded' : 'ok');

        return [
            'status' => $status,
            'checks' => $checks,
            'flags' => [
                'tenant_rls_enabled' => TenantRlsManager::isEnabled(),
                'tenant_orm_global_scope' => (bool) config('tenants.isolation.orm_global_scope', false),
                'tenant_api_rate_limit_enabled' => (bool) config('tenants.api.rate_limit.enabled', true),
            ],
            'version' => (string) config('app.version', '1.0.0'),
        ];
    }

    /**
     * @return array{status: string, latency_ms?: float, message?: string}
     */
    private function checkDatabase(): array
    {
        $started = microtime(true);

        try {
            DB::select('select 1');

            return [
                'status' => 'ok',
                'latency_ms' => round((microtime(true) - $started) * 1000, 2),
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'fail',
                'message' => 'database_unreachable',
            ];
        }
    }

    /**
     * @return array{status: string, latency_ms?: float, message?: string}
     */
    private function checkCache(): array
    {
        $started = microtime(true);
        $key = 'platform:health:'.uniqid('', true);

        try {
            Cache::put($key, 'ok', 10);
            $value = Cache::get($key);
            Cache::forget($key);

            if ($value !== 'ok') {
                return ['status' => 'degraded', 'message' => 'cache_read_mismatch'];
            }

            return [
                'status' => 'ok',
                'latency_ms' => round((microtime(true) - $started) * 1000, 2),
            ];
        } catch (\Throwable) {
            return ['status' => 'degraded', 'message' => 'cache_unavailable'];
        }
    }

    /**
     * @return array{status: string, message?: string}
     */
    private function checkQueue(): array
    {
        try {
            $connection = (string) config('queue.default', 'sync');

            if ($connection === 'sync') {
                return ['status' => 'degraded', 'message' => 'queue_sync_driver'];
            }

            Queue::connection($connection);

            return ['status' => 'ok'];
        } catch (\Throwable) {
            return ['status' => 'fail', 'message' => 'queue_unreachable'];
        }
    }

    /**
     * @return array{status: string, message?: string}
     */
    private function checkStorage(): array
    {
        try {
            $disk = (string) config('tenants.export.disk', 'local');
            $path = 'healthchecks/'.uniqid('', true).'.txt';
            Storage::disk($disk)->put($path, 'ok');
            Storage::disk($disk)->delete($path);

            return ['status' => 'ok'];
        } catch (\Throwable) {
            return ['status' => 'degraded', 'message' => 'storage_unwritable'];
        }
    }
}
