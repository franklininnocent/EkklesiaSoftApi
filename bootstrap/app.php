<?php

use App\Http\Middleware\PassportAuthenticate;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\TenantFileAccessMiddleware;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Modules\ApplicationAccess\Http\Middleware\AssignRequestId;
use Modules\ApplicationAccess\Http\Middleware\CaptureApplicationAccess;
use Modules\ApplicationAccess\Http\Middleware\EnforceApplicationIpBlocks;
use Modules\ApplicationAccess\Http\Middleware\EnsureApplicationAccessPermission;
use Modules\ApplicationAccess\Support\TrustedProxyConfiguration;
use Modules\Donations\Http\Middleware\EnsureDonationFeatureEnabled;
use Modules\Donations\Http\Middleware\LogDonationSecurityResponse;
use Modules\EcclesiasticalData\Http\Middleware\EnsureEcclesiasticalPermission;
use Modules\MinistriesAssociations\Http\Middleware\EnsureAdminMinistriesPermission;
use Modules\MinistriesAssociations\Http\Middleware\EnsureMinistriesFeatureEnabled;
use Modules\RolesAndPermissions\Http\Middleware\EnsureTenantPermission;
use Modules\SupportAccess\Http\Middleware\EnforceSupportSessionMode;
use Modules\SupportAccess\Http\Middleware\EnsureSupportPermission;
use Modules\SupportTickets\Http\Middleware\EnsureParishTicketActor;
use Modules\Tenants\Http\Middleware\ApplyTenantRlsTransaction;
use Modules\Tenants\Http\Middleware\EnforceApiPaginationLimits;
use Modules\Tenants\Http\Middleware\EnsureSubscriptionAccess;
use Modules\Tenants\Http\Middleware\EnsureSubscriptionAccessMode;
use Modules\Tenants\Http\Middleware\LogPlatformSecurityEvents;
use Modules\Tenants\Http\Middleware\ResolveTenantContext;
use Modules\Tenants\Http\Middleware\ThrottleTenantApiRequests;
use Symfony\Component\HttpFoundation\Response;

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
        $middleware->prepend(HandleCors::class);

        $trustedProxies = TrustedProxyConfiguration::proxies();
        if ($trustedProxies !== []) {
            $middleware->trustProxies(
                at: $trustedProxies,
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO
            );
        }

        // Register auth middleware alias for Passport
        $middleware->alias([
            'auth' => Authenticate::class,
            'passport' => PassportAuthenticate::class,
            'tenant.permission' => EnsureTenantPermission::class,
            'tenant.feature.donations' => EnsureDonationFeatureEnabled::class,
            'donations.security.log' => LogDonationSecurityResponse::class,
            'tenant.feature.ministries' => EnsureMinistriesFeatureEnabled::class,
            'tenant.subscription' => EnsureSubscriptionAccess::class,
            'support.permission' => EnsureSupportPermission::class,
            'support.mode' => EnforceSupportSessionMode::class,
            'support.ticket.parish_actor' => EnsureParishTicketActor::class,
            'ministries.platform.permission' => EnsureAdminMinistriesPermission::class,
            'ecclesiastical.permission' => EnsureEcclesiasticalPermission::class,
            'api.throttle' => ThrottleTenantApiRequests::class,
            'application_access.permission' => EnsureApplicationAccessPermission::class,
        ]);

        // Configure API middleware group - set default guard to API
        // Order: bindings → Passport identity → TenantContext bind (SSOT for effective tenant)
        $middleware->api(prepend: [
            AssignRequestId::class,
            SubstituteBindings::class,
            PassportAuthenticate::class,
            EnforceApplicationIpBlocks::class,
            ResolveTenantContext::class,
            EnforceApiPaginationLimits::class,
            ThrottleTenantApiRequests::class,
            ApplyTenantRlsTransaction::class,
            EnforceSupportSessionMode::class,
            EnsureSubscriptionAccessMode::class,
            CaptureApplicationAccess::class,
        ]);

        // Add security headers to all responses
        $middleware->append(SecurityHeadersMiddleware::class);

        // Platform security audit trail for auth failures (Phase 10)
        $middleware->append(LogPlatformSecurityEvents::class);

        // Add tenant file access control
        $middleware->append(TenantFileAccessMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
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
