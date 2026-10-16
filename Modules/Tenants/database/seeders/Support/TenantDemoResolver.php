<?php

namespace Modules\Tenants\Database\Seeders\Support;

use Illuminate\Support\Facades\Auth;
use Modules\Authentication\Models\User;
use Modules\Tenants\Database\Seeders\Support\CompactDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\SacramentsDemoMarkers;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;

final class TenantDemoResolver
{
    public static function resolveTenant(?int $explicitId = null): ?Tenant
    {
        return DemoSeedGuard::resolveTenant($explicitId);
    }

    public static function resolveActor(int $tenantId): ?User
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->where('active', 1)
            ->orderBy('id')
            ->first()
            ?? User::query()->where('tenant_id', $tenantId)->orderBy('id')->first();
    }

    public static function bindTenantContext(User $actor, int $tenantId): void
    {
        Auth::login($actor);
        app()->instance(TenantContext::class, new TenantContext(
            (int) $actor->id,
            $actor->tenant_id ? (int) $actor->tenant_id : $tenantId,
            $tenantId,
            null,
            null,
        ));
    }

    public static function propagateChildSeederEnv(int $tenantId): void
    {
        $id = (string) $tenantId;
        putenv('TENANT_DEMO_TENANT_ID='.$id);
        $_ENV['TENANT_DEMO_TENANT_ID'] = $id;
        $_SERVER['TENANT_DEMO_TENANT_ID'] = $id;

        putenv('BCC_DUMMY_TENANT_ID='.$id);
        $_ENV['BCC_DUMMY_TENANT_ID'] = $id;
        $_SERVER['BCC_DUMMY_TENANT_ID'] = $id;

        putenv('BCC_LEADERSHIP_DEMO_TENANT_ID='.$id);
        $_ENV['BCC_LEADERSHIP_DEMO_TENANT_ID'] = $id;
        $_SERVER['BCC_LEADERSHIP_DEMO_TENANT_ID'] = $id;

        putenv('STEWARDSHIP_DEMO_TENANT_ID='.$id);
        $_ENV['STEWARDSHIP_DEMO_TENANT_ID'] = $id;
        $_SERVER['STEWARDSHIP_DEMO_TENANT_ID'] = $id;

        putenv('BCC_DUMMY_FAMILIES_MIN=5');
        $_ENV['BCC_DUMMY_FAMILIES_MIN'] = '5';
        $_SERVER['BCC_DUMMY_FAMILIES_MIN'] = '5';

        putenv('BCC_DUMMY_FAMILIES_MAX=5');
        $_ENV['BCC_DUMMY_FAMILIES_MAX'] = '5';
        $_SERVER['BCC_DUMMY_FAMILIES_MAX'] = '5';

        putenv(CompactDemoMarkers::ENV_ONLY_DEMO_BCCS.'=1');
        $_ENV[CompactDemoMarkers::ENV_ONLY_DEMO_BCCS] = '1';
        $_SERVER[CompactDemoMarkers::ENV_ONLY_DEMO_BCCS] = '1';

        putenv(CompactDemoMarkers::ENV_COMPACT.'=1');
        $_ENV[CompactDemoMarkers::ENV_COMPACT] = '1';
        $_SERVER[CompactDemoMarkers::ENV_COMPACT] = '1';

        putenv(SacramentsDemoMarkers::ENV_TARGET.'='.CompactDemoMarkers::SACRAMENT_REGISTER_TARGET);
        $_ENV[SacramentsDemoMarkers::ENV_TARGET] = (string) CompactDemoMarkers::SACRAMENT_REGISTER_TARGET;
        $_SERVER[SacramentsDemoMarkers::ENV_TARGET] = (string) CompactDemoMarkers::SACRAMENT_REGISTER_TARGET;
    }

    public static function flagIsTrue(string $envKey): bool
    {
        $raw = env($envKey);
        if ($raw === null || $raw === '') {
            $raw = $_ENV[$envKey] ?? $_SERVER[$envKey] ?? getenv($envKey);
        }

        if ($raw === false || $raw === null || $raw === '') {
            return false;
        }

        if ($raw === true || $raw === 1 || $raw === '1') {
            return true;
        }

        if ($raw === false || $raw === 0 || $raw === '0') {
            return false;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOL);
    }

    /**
     * Resolve tenant id from CLI/env (works when config is cached).
     */
    public static function readEnvTenantId(): ?int
    {
        $raw = env(TenantDemoMarkers::ENV_TENANT_ID);
        if ($raw === null || $raw === '') {
            $raw = $_ENV[TenantDemoMarkers::ENV_TENANT_ID]
                ?? $_SERVER[TenantDemoMarkers::ENV_TENANT_ID]
                ?? getenv(TenantDemoMarkers::ENV_TENANT_ID);
        }

        if ($raw === false || $raw === null || $raw === '') {
            return null;
        }

        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }
}
