<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use League\OAuth2\Server\ResourceServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\HttpFoundation\Response;

class PassportAuthenticate
{
    protected $server;

    public function __construct(ResourceServer $server)
    {
        $this->server = $server;
    }

    /**
     * Handle an incoming request and manually authenticate via Passport.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Never attempt auth/token parsing for CORS preflight requests.
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $token = $request->bearerToken();

        if ($token) {
            try {
                // Convert to PSR-7 request
                $psr17Factory = new Psr17Factory;
                $psrRequest = $psr17Factory->createServerRequest(
                    $request->method(),
                    $request->fullUrl(),
                    $request->server->all()
                );
                $psrRequest = $psrRequest->withHeader('Authorization', 'Bearer '.$token);

                // Validate through Passport's ResourceServer
                $psrRequest = $this->server->validateAuthenticatedRequest($psrRequest);

                // Get user ID from validated request
                $userId = $psrRequest->getAttribute('oauth_user_id');

                if ($userId) {
                    $user = User::find($userId);
                    if ($user && (int) $user->active === 1) {
                        auth()->guard('api')->setUser($user);
                        $request->setUserResolver(function () use ($user) {
                            return $user;
                        });
                    }
                }
            } catch (\Exception $e) {
                // Token validation failed - continue without authentication
                \Log::debug('Passport authentication failed: '.$e->getMessage());
            }
        }

        if ($denied = $this->denyInactiveApiUser($request)) {
            return $denied;
        }

        return $next($request);
    }

    /**
     * Deactivated accounts must not execute API calls even with a leftover
     * bearer token or a test-time Passport::actingAs() identity.
     */
    private function denyInactiveApiUser(Request $request): ?Response
    {
        $user = auth()->guard('api')->user() ?? $request->user();
        if ($user === null || (int) ($user->active ?? 0) === 1) {
            return null;
        }

        return response()->json([
            'message' => 'Unauthenticated.',
        ], 401);
    }
}
