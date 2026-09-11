<?php

namespace Modules\SupportTickets\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Parish ticket mutations must be performed by tenant users, not via support session.
 */
class EnsureParishTicketActor
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);

        if ($context->isSupportSession()) {
            return response()->json([
                'success' => false,
                'message' => 'Support sessions cannot modify parish tickets. Use Support Center.',
            ], 403);
        }

        return $next($request);
    }
}
