<?php

namespace Modules\Tenants\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\Tenants\Support\TenantContext;

/**
 * Fail-closed global scope for tenant-owned models.
 *
 * When enabled and no effective tenant is bound, queries return zero rows.
 * Platform jobs and console commands must use withoutTenantScope() or bind TenantContext.
 */
final class TenantScope implements Scope
{
    public const COLUMN = 'tenant_id';

    private static int $disabledDepth = 0;

    public static function disable(): void
    {
        self::$disabledDepth++;
    }

    public static function enable(): void
    {
        self::$disabledDepth = max(0, self::$disabledDepth - 1);
    }

    public static function isDisabled(): bool
    {
        return self::$disabledDepth > 0;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function runWithout(callable $callback): mixed
    {
        self::disable();

        try {
            return $callback();
        } finally {
            self::enable();
        }
    }

    public function apply(Builder $builder, Model $model): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $tenantId = $this->resolveEffectiveTenantId();

        if ($tenantId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where(
            $model->qualifyColumn(self::COLUMN),
            $tenantId
        );
    }

    public function isEnabled(): bool
    {
        if (TenantScope::isDisabled()) {
            return false;
        }

        return (bool) config('tenants.isolation.orm_global_scope', true);
    }

    public function resolveEffectiveTenantId(): ?int
    {
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
}
