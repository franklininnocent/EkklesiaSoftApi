<?php

namespace Modules\Authentication\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Authentication\Models\User;
use Symfony\Component\HttpFoundation\Response;

class EnsureForcedPasswordChange
{
    /**
     * @var list<string>
     */
    private const ALLOWED_PATHS = [
        'api/auth/password/change',
        'api/auth/logout',
        'api/auth/refresh',
        'api/auth/get-user',
        'api/auth/user',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->force_password_change) {
            return $next($request);
        }

        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $path = trim($request->path(), '/');

        foreach (self::ALLOWED_PATHS as $allowed) {
            if ($path === $allowed) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'You must change your password before continuing.',
            'code' => 'FORCE_PASSWORD_CHANGE',
        ], 403);
    }
}
