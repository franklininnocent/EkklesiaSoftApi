<?php

namespace Modules\Tenants\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @deprecated Dead middleware. Do not register.
 * Use ResolveTenantContext + TenantContext instead.
 * SuperAdmin bypass here would fight Support Session accountability.
 */
class EnsureTenantAccess
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Intentionally unused — left for reference until SupportAccess Phase 1 cleanup.
        return $next($request);
    }
}
