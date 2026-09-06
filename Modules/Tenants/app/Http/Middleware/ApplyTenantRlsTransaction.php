<?php

namespace Modules\Tenants\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Tenants\Support\TenantRlsManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wraps tenant API requests in a DB transaction so SET LOCAL tenant GUC is safe
 * under PgBouncer transaction pooling.
 */
class ApplyTenantRlsTransaction
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! TenantRlsManager::shouldApplyForHttp($request)) {
            return $next($request);
        }

        return DB::transaction(static fn () => $next($request));
    }
}
