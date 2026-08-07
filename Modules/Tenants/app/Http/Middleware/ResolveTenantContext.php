<?php

namespace Modules\Tenants\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Tenants\Contracts\SupportSessionResolver;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds TenantContext for the request after Passport identity is established.
 * Supersedes the unwired SetTenantContext middleware.
 */
class ResolveTenantContext
{
    public function __construct(
        private readonly SupportSessionResolver $supportSessionResolver,
    ) {
    }

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()
            ?? Auth::guard('api')->user()
            ?? Auth::user();

        $session = null;
        if ($user instanceof Authenticatable) {
            $session = $this->supportSessionResolver->resolve($request, $user);
        }

        $context = TenantContext::fromUserAndSession(
            $user instanceof Authenticatable ? $user : null,
            $session,
        );

        app()->instance(TenantContext::class, $context);
        $request->attributes->set('tenant_context', $context);

        return $next($request);
    }
}
