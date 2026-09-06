<?php

namespace Modules\Tenants\Console\Commands;

use Illuminate\Console\Command;
use Modules\Tenants\Support\TenantRlsManager;

class PlatformProductionCheck extends Command
{
    protected $signature = 'platform:production-check {--strict : Fail on warnings as well as errors}';

    protected $description = 'Validate production security and operability configuration.';

    public function handle(): int
    {
        $strict = (bool) $this->option('strict');
        $checks = [
            $this->check('APP_ENV is production', fn () => config('app.env') === 'production', true),
            $this->check('APP_DEBUG is false', fn () => config('app.debug') === false, true),
            $this->check('APP_KEY is set', fn () => (string) config('app.key') !== '', true),
            $this->check('TENANT_ORM_GLOBAL_SCOPE enabled', fn () => (bool) config('tenants.isolation.orm_global_scope', false), true),
            $this->check('TENANT_RLS_ENABLED on PostgreSQL', function (): bool {
                if (config('database.default') !== 'pgsql') {
                    return true;
                }

                return TenantRlsManager::isEnabled();
            }, true),
            $this->check('Queue driver is not sync', fn () => config('queue.default') !== 'sync', $strict),
            $this->check('Platform health token configured', fn () => (string) config('tenants.platform.health.token', '') !== '', $strict),
            $this->check('Session secure cookie in production', fn () => (bool) config('session.secure', false), $strict),
            $this->check('Export cleanup scheduled', fn () => (bool) config('tenants.platform.scheduler.export_cleanup_enabled', true), false),
        ];

        $rows = [];
        $failed = 0;
        $warned = 0;

        foreach ($checks as $check) {
            $rows[] = [$check['label'], $check['status']];

            if ($check['status'] === 'FAIL') {
                $failed++;
            }

            if ($check['status'] === 'WARN') {
                $warned++;
            }
        }

        $this->table(['Check', 'Result'], $rows);

        if ($failed > 0 || ($strict && $warned > 0)) {
            $this->error('Production check failed.');

            return self::FAILURE;
        }

        $this->info('Production check passed.');

        return self::SUCCESS;
    }

    /**
     * @return array{label: string, status: string}
     */
    private function check(string $label, callable $callback, bool $strict): array
    {
        $passed = (bool) $callback();

        if ($passed) {
            return ['label' => $label, 'status' => 'PASS'];
        }

        return ['label' => $label, 'status' => $strict ? 'FAIL' : 'WARN'];
    }
}
