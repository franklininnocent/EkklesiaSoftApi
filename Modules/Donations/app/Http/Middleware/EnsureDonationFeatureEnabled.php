<?php

namespace Modules\Donations\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDonationFeatureEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user || !$user->tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context is required.',
            ], 403);
        }

        if (!$user->tenant->supportsDonations()) {
            return response()->json([
                'success' => false,
                'message' => 'Donations feature is not enabled for this tenant.',
            ], 403);
        }

        return $next($request);
    }
}
