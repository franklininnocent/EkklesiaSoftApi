<?php

namespace Modules\Tenants\Database\Seeders\Support;

use Illuminate\Console\Command;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;

/**
 * Production and tenant-scoping guards for demo / load-test seeding (CLI only).
 */
final class DemoSeedGuard
{
    public const ENV_ALLOW = 'APP_ALLOW_DEMO_SEED';

    public const ENV_CLI_FORCE = 'DEMO_SEED_CLI_FORCE';

    /**
     * @return list<string> Empty when allowed; otherwise human-readable block reasons.
     */
    public static function environmentBlocks(): array
    {
        if (! app()->environment('production')) {
            return [];
        }

        $allow = filter_var(env(self::ENV_ALLOW, false), FILTER_VALIDATE_BOOL);
        $force = filter_var(env(self::ENV_CLI_FORCE, false), FILTER_VALIDATE_BOOL);

        if ($allow && $force) {
            return [];
        }

        return [
            'Demo seeding is blocked in production. Set '.self::ENV_ALLOW.'=1 and pass --force on the Artisan command.',
        ];
    }

    public static function assertEnvironmentAllowed(?Command $command = null): bool
    {
        $blocks = self::environmentBlocks();
        if ($blocks === []) {
            return true;
        }

        foreach ($blocks as $message) {
            $command?->error($message);
        }

        return false;
    }

    /**
     * Resolve demo tenant with environment-appropriate rules (no Sacred Heart preference).
     */
    public static function resolveTenant(?int $explicitId = null): ?Tenant
    {
        if ($explicitId !== null && $explicitId > 0) {
            return Tenant::query()->find($explicitId);
        }

        $fromEnv = TenantDemoResolver::readEnvTenantId();
        if ($fromEnv !== null) {
            return Tenant::query()->find($fromEnv);
        }

        if (app()->environment('production')) {
            return null;
        }

        if (app()->environment(['local', 'testing'])) {
            return Tenant::query()->where('active', 1)->orderBy('id')->first()
                ?? Tenant::query()->orderBy('id')->first();
        }

        return null;
    }

    public static function requireExplicitTenantId(?Command $command = null): bool
    {
        if (! app()->environment('production')) {
            return true;
        }

        if (TenantDemoResolver::readEnvTenantId() !== null) {
            return true;
        }

        $command?->error('Production demo seeding requires --tenant= or TENANT_DEMO_TENANT_ID.');

        return false;
    }

    public static function resolveActorForTenant(int $tenantId): ?User
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->where('active', 1)
            ->orderBy('id')
            ->first()
            ?? User::query()->where('tenant_id', $tenantId)->orderBy('id')->first();
    }

    public static function setCliForceFromCommand(Command $command): void
    {
        if ($command->option('force')) {
            putenv(self::ENV_CLI_FORCE.'=1');
            $_ENV[self::ENV_CLI_FORCE] = '1';
            $_SERVER[self::ENV_CLI_FORCE] = '1';
        }
    }
}
