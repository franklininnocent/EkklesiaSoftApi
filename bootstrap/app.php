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
        //
    })->create();
