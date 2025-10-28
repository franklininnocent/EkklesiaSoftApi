<?php

namespace Modules\EcclesiasticalData\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsEkklesia
{
    /**
     * Handle an incoming request.
     *
     * Ensures that only Ekklesia users (SuperAdmin, EkklesiaAdmin, EkklesiaManager, EkklesiaUser)
     * can access Ecclesiastical Data Management endpoints.
     * 
     * Tenant users, regardless of their permissions, CANNOT access this module.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Ensure user is authenticated
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
                'error' => 'You must be logged in to access this resource.'
            ], 401);
        }

        // Check if user has an Ekklesia role
        if (!$user->hasEkklesiaRole()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden.',
                'error' => 'Access to Ecclesiastical Data Management is restricted to Ekklesia administrators only.'
            ], 403);
        }

        return $next($request);
    }
}

