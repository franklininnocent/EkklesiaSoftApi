<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeadersMiddleware
{
    /**
     * Add comprehensive security headers to all responses.
     * 
     * Implements OWASP security best practices for web applications.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        
        // Content Security Policy (CSP)
        // Prevents XSS attacks by controlling what resources can be loaded
        $cspDirectives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'", // Allow inline scripts for Angular
            "style-src 'self' 'unsafe-inline'", // Allow inline styles
            "img-src 'self' data: https: blob:", // Allow images from various sources
            "font-src 'self' data:",
            "connect-src 'self' http://localhost:* http://127.0.0.1:*", // API connections
            "media-src 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ];
        
        $response->headers->set('Content-Security-Policy', implode('; ', $cspDirectives));
        
        // X-Frame-Options: Prevents clickjacking attacks
        $response->headers->set('X-Frame-Options', 'DENY');
        
        // X-Content-Type-Options: Prevents MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        
        // X-XSS-Protection: Enables XSS filter in browsers (legacy support)
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        
        // Referrer-Policy: Controls referrer information
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        
        // Permissions-Policy: Controls browser features
        $permissionsDirectives = [
            'geolocation=()',
            'microphone=()',
            'camera=()',
            'payment=()',
            'usb=()',
            'magnetometer=()',
            'gyroscope=()',
            'accelerometer=()',
        ];
        
        $response->headers->set('Permissions-Policy', implode(', ', $permissionsDirectives));
        
        // Strict-Transport-Security (HSTS): Enforces HTTPS (only in production)
        if (config('app.env') === 'production') {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }
        
        // X-Permitted-Cross-Domain-Policies: Restricts Flash/PDF cross-domain access
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        
        // X-Download-Options: Prevents IE from executing downloads in site context
        $response->headers->set('X-Download-Options', 'noopen');
        
        // Cache-Control: Prevent caching of sensitive data
        if ($request->is('api/*') && !$request->is('api/geography/*')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }
        
        // Remove potentially sensitive headers
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');
        
        return $response;
    }
}

