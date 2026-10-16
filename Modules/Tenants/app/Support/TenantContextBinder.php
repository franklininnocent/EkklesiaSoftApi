<?php

namespace Modules\Tenants\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * Bind a trusted TenantContext for queues, exports, and other non-HTTP runtimes.
 */
final class TenantContextBinder
{
    public static function bind(
        int $effectiveTenantId,
        ?int $actorUserId = null,
        ?int $homeTenantId = null,
    ): TenantContext {
        $context = new TenantContext(
            $actorUserId,
            $homeTenantId,
            $effectiveTenantId,
            null,
            null,
        );

        app()->instance(TenantContext::class, $context);

        return $context;
    }

    public static function bindForActor(Authenticatable $user, ?int $effectiveTenantId = null): TenantContext
    {
        $actorUserId = (int) $user->getAuthIdentifier();
        $homeTenantId = isset($user->tenant_id) && $user->tenant_id !== null
            ? (int) $user->tenant_id
            : null;
        $effectiveTenantId ??= $homeTenantId;

        if ($effectiveTenantId === null || $effectiveTenantId <= 0) {
            throw new \InvalidArgumentException('Effective tenant id is required to bind TenantContext.');
        }

        Auth::setUser($user);

        return self::bind($effectiveTenantId, $actorUserId, $homeTenantId);
    }

    public static function clear(): void
    {
        app()->instance(TenantContext::class, TenantContext::empty());
    }
}
