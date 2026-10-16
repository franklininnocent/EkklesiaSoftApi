<?php

namespace Modules\ApplicationAccess\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public const ATTRIBUTE = 'application_access.request_id';

    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = trim((string) $request->header(self::HEADER, ''));
        $requestId = $incoming !== '' ? substr($incoming, 0, 64) : (string) Str::uuid();

        $request->attributes->set(self::ATTRIBUTE, $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
