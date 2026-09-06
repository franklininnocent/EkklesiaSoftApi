<?php

namespace Modules\Tenants\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Tenants\Support\ApiPagination;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clamp ?per_page= on GET list endpoints before controllers run.
 */
class EnforceApiPaginationLimits
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('tenants.api.pagination.enabled', true)) {
            return $next($request);
        }

        if (! $request->isMethod('GET') || ! $request->has('per_page')) {
            return $next($request);
        }

        $raw = $request->input('per_page');
        if ($raw === 'all') {
            return $next($request);
        }

        if (is_numeric($raw)) {
            $request->merge([
                'per_page' => ApiPagination::clamp((int) $raw),
            ]);
        }

        return $next($request);
    }
}
