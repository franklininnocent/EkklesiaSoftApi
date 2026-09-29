<?php

namespace Modules\ApplicationAccess\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\ApplicationAccess\Support\ApplicationIpBlockMatcher;
use Symfony\Component\HttpFoundation\Response;

class EnforceApplicationIpBlocks
{
    public function __construct(
        private readonly ApplicationIpBlockMatcher $matcher,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS') || trim($request->path(), '/') === 'up') {
            return $next($request);
        }

        if ($this->matcher->isBlocked($request->ip())) {
            return response()->json([
                'success' => false,
                'message' => 'Access from this IP address is blocked.',
                'code' => 'ip_blocked',
            ], 403);
        }

        return $next($request);
    }
}
