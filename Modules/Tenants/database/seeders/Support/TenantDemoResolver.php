<?php

namespace Modules\Tenants\Database\Seeders\Support;

use Illuminate\Support\Facades\Auth;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;

final class TenantDemoResolver
{
    /**
     * @var list<string>
     */
    private const PREFERRED_TENANT_NAMES = [
        'Sacred Heart Church',
        'Sacred Heart Parish',
    ];

    public static function resolveTenant(?int $explicitId = null): ?Tenant
    {
        if ($explicitId !== null) {
            return Tenant::query()->find($explicitId);
        }

        $fromEnv = self::readEnvTenantId();
        if ($fromEnv !== null) {
            $tenant = Tenant::query()->find($fromEnv);
            if ($tenant) {
                return $tenant;
            }
        }

        foreach (self::PREFERRED_TENANT_NAMES as $name) {
            $tenant = Tenant::query()->where('name', $name)->first();
            if ($tenant) {
                return $tenant;
            }
        }

        $sacred = Tenant::query()
            ->where('name', 'ILIKE', '%Sacred Heart%')
            ->orderBy('id')
            ->first();

        if ($sacred) {
            return $sacred;
        }

        return Tenant::query()->where('active', 1)->orderBy('id')->first()
            ?? Tenant::query()->orderBy('id')->first();
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
    }

    public static function flagIsTrue(string $envKey): bool
    {
        return filter_var(env($envKey, false), FILTER_VALIDATE_BOOL);
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
