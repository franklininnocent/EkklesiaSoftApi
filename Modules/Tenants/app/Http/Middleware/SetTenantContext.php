<?php

namespace Modules\Tenants\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class SetTenantContext
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check()) {
            $user = auth()->user();
            $tenantId = $user->tenant_id;

            // Set tenant context in request
            $request->merge(['current_tenant_id' => $tenantId]);

            // Store in config for global access
            config(['app.current_tenant_id' => $tenantId]);

            // Log tenant context (useful for debugging multi-tenant issues)
            if ($tenantId) {
                Log::debug('Tenant context set', [
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'user_role' => $user->role?->name,
                ]);
            }
        }

        return $next($request);
    }
}

