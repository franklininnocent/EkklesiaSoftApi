<?php

namespace Modules\Tenants\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant- and user-scoped API rate limiting with configurable buckets.
 */
class ThrottleTenantApiRequests
{
    public function handle(Request $request, Closure $next, string $bucket = 'default'): Response
    {
        if (! config('tenants.api.rate_limit.enabled', true)) {
            return $next($request);
        }

        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        if ($bucket === 'default' && $this->shouldSkipDefaultThrottle($request)) {
            return $next($request);
        }

        $limits = config("tenants.api.rate_limit.buckets.{$bucket}")
            ?? config('tenants.api.rate_limit.buckets.default', []);

        $maxAttempts = max(1, (int) ($limits['max_attempts'] ?? 120));
        $decaySeconds = max(1, (int) ($limits['decay_seconds'] ?? 60));
        $key = $this->resolveKey($request, $bucket);

        try {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                $retryAfter = RateLimiter::availableIn($key);

                return response()->json([
                    'success' => false,
                    'message' => 'Too many requests. Please slow down and try again.',
                    'retry_after_seconds' => $retryAfter,
                ], 429)->withHeaders($this->rateLimitHeaders($maxAttempts, 0, $retryAfter));
            }

            RateLimiter::hit($key, $decaySeconds);
        } catch (\Throwable $exception) {
            Log::warning('API rate limiter unavailable', [
                'exception' => $exception::class,
            ]);

            return $next($request);
        }

        $response = $next($request);

        try {
            $remaining = max(0, $maxAttempts - RateLimiter::attempts($key));
        } catch (\Throwable $exception) {
            Log::warning('API rate limiter unavailable', [
                'exception' => $exception::class,
            ]);

            return $response;
        }

        return $this->attachRateLimitHeaders($response, $this->rateLimitHeaders($maxAttempts, $remaining));
    }

    private function attachRateLimitHeaders(Response $response, array $headers): Response
    {
        foreach ($headers as $name => $value) {
            $response->headers->set($name, (string) $value);
        }

        return $response;
    }

    private function shouldSkipDefaultThrottle(Request $request): bool
    {
        return $request->is(
            'api/auth/login',
            'api/auth/register',
            'api/auth/refresh',
            'api/auth/password/recovery/*',
            'api/public/*',
        );
    }

    private function resolveKey(Request $request, string $bucket): string
    {
        $tenantId = app(TenantContext::class)->effectiveTenantId();
        $userId = $request->user()?->id ?? 'guest';
        $tenantPart = $tenantId !== null && $tenantId > 0 ? (string) $tenantId : 'platform';
        $ip = $this->resolveClientIp($request);

        return "api:{$bucket}:t{$tenantPart}:u{$userId}:{$ip}";
    }

    private function resolveClientIp(Request $request): string
    {
        $secret = (string) config('marketing.bff.shared_secret', '');
        $provided = (string) $request->header('X-Marketing-Proxy-Secret', '');
        if ($secret !== '' && $provided !== '' && hash_equals($secret, $provided)) {
            $forwarded = trim((string) $request->header('X-Marketing-Client-Ip', ''));
            if (filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
                return $forwarded;
            }
        }

        return (string) $request->ip();
    }

    /**
     * @return array<string, int|string>
     */
    private function rateLimitHeaders(int $limit, int $remaining, ?int $retryAfter = null): array
    {
        $headers = [
            'X-RateLimit-Limit' => $limit,
            'X-RateLimit-Remaining' => $remaining,
        ];

        if ($retryAfter !== null) {
            $headers['Retry-After'] = $retryAfter;
        }

        return $headers;
    }
}
