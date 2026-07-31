<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Enable CORS for API requests
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);
        
        // Register auth middleware alias for Passport
        $middleware->alias([
            'auth' => \Illuminate\Auth\Middleware\Authenticate::class,
            'passport' => \App\Http\Middleware\PassportAuthenticate::class,
            'tenant.permission' => \Modules\RolesAndPermissions\Http\Middleware\EnsureTenantPermission::class,
            'tenant.feature.donations' => \Modules\Donations\Http\Middleware\EnsureDonationFeatureEnabled::class,
        ]);
        
        // Configure API middleware group - set default guard to API  
        $middleware->api(prepend: [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\PassportAuthenticate::class,
        ]);
        
        // Add security headers to all responses
        $middleware->append(\App\Http\Middleware\SecurityHeadersMiddleware::class);
        
        // Add tenant file access control
        $middleware->append(\App\Http\Middleware\TenantFileAccessMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response, \Throwable $exception, \Illuminate\Http\Request $request) {
            if (!$request->is('api/*')) {
                return $response;
            }

            $origin = $request->headers->get('Origin');
            $allowedOrigins = config('cors.allowed_origins', []);

            if ($origin && in_array($origin, $allowedOrigins, true)) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
                $response->headers->set('Vary', 'Origin');
            }

            return $response;
        });
    })->create();
