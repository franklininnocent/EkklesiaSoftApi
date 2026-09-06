<?php

namespace Modules\Tenants\Support;

use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Tenants\Models\Scopes\TenantScope;

/**
 * PostgreSQL row-level security bridge for TenantContext.
 *
 * Uses transaction-scoped set_config('app.current_tenant', …, true) so PgBouncer
 * transaction pooling cannot leak tenant identity across requests.
 */
final class TenantRlsManager
{
    public const GUC_NAME = 'app.current_tenant';

    /**
     * Wave-1 tables protected by tenant isolation policies.
     *
     * @var list<string>
     */
    public const WAVE_ONE_TABLES = [
        'families',
        'persons',
        'family_members',
        'donation_payments',
        'sacraments',
        'pastoral_care_requests',
    ];

    public static function isEnabled(): bool
    {
        if (! (bool) config('tenants.isolation.rls_enabled', false)) {
            return false;
        }

        return DB::getDriverName() === 'pgsql';
    }

    public static function shouldApplyForHttp(Request $request): bool
    {
        if (! self::isEnabled() || ! $request->is('api/*')) {
            return false;
        }

        return self::resolveTenantIdFromContext() !== null;
    }

    public static function resolveTenantIdFromContext(): ?int
    {
        if (TenantScope::isDisabled()) {
            return null;
        }

        try {
            $tenantId = app(TenantContext::class)->effectiveTenantId();
        } catch (\Throwable) {
            return null;
        }

        if ($tenantId === null || $tenantId <= 0) {
            return null;
        }

        return $tenantId;
    }

    public static function applyLocalTenantFromContext(?Connection $connection = null): void
    {
        $tenantId = self::resolveTenantIdFromContext();

        if ($tenantId === null) {
            return;
        }

        self::applyLocalTenant($connection, $tenantId);
    }

    public static function applyLocalTenant(?Connection $connection, int $tenantId): void
    {
        if (! self::isEnabled() || $tenantId <= 0) {
            return;
        }

        $connection ??= DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement(
            "SELECT set_config(?, ?, true)",
            [self::GUC_NAME, (string) $tenantId]
        );
    }

    /**
     * Execute a callback inside a transaction with an explicit tenant GUC.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function runWithTenant(int $tenantId, callable $callback): mixed
    {
        return DB::transaction(function () use ($tenantId, $callback) {
            self::applyLocalTenant(null, $tenantId);

            return $callback();
        });
    }

    public static function policySql(string $table): string
    {
        $table = str_replace('"', '', $table);

        return sprintf(
            "tenant_id = NULLIF(current_setting('%s', true), '')::bigint",
            self::GUC_NAME
        );
    }
}
