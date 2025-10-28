<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Laravel\Passport\Token;
use Nyholm\Psr7\Factory\Psr17Factory;
use League\OAuth2\Server\ResourceServer;

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
        $token = $request->bearerToken();

        if ($token) {
            try {
                // Convert to PSR-7 request
                $psr17Factory = new Psr17Factory();
                $psrRequest = $psr17Factory->createServerRequest(
                    $request->method(),
                    $request->fullUrl(),
                    $request->server->all()
                );
                $psrRequest = $psrRequest->withHeader('Authorization', 'Bearer ' . $token);

                // Validate through Passport's ResourceServer
                $psrRequest = $this->server->validateAuthenticatedRequest($psrRequest);

                // Get user ID from validated request
                $userId = $psrRequest->getAttribute('oauth_user_id');

                if ($userId) {
                    // Find and set the user on the API guard
                    $user = \App\Models\User::find($userId);
                    if ($user) {
                        auth()->guard('api')->setUser($user);
                        $request->setUserResolver(function () use ($user) {
                            return $user;
                        });
                    }
                }
            } catch (\Exception $e) {
                // Token validation failed - continue without authentication
                \Log::debug('Passport authentication failed: ' . $e->getMessage());
            }
        }

        return $next($request);
    }
}

