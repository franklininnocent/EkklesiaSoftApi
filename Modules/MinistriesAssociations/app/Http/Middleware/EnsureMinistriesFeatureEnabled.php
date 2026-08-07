<?php

namespace Modules\MinistriesAssociations\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMinistriesFeatureEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context is required.',
                'errors' => [],
            ], 403);
        }

        if (! $user->tenant->supportsMinistriesAssociations()) {
            return response()->json([
                'success' => false,
                'message' => 'Ministries & Associations is not enabled for this church.',
                'errors' => [],
            ], 403);
        }

        return $next($request);
    }
}
