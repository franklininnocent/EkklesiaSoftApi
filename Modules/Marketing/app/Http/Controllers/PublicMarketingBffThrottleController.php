<?php

namespace Modules\Marketing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Shared rate-limit counter for the Next.js BFF. Authenticated only by
 * MARKETING_BFF_SECRET so every web instance uses Laravel's cache (Redis).
 */
class PublicMarketingBffThrottleController extends Controller
{
    private const BUCKETS = [
        'marketing_bff_forms' => 'public_forms',
        'marketing_bff_captcha' => 'public_captcha',
    ];

    public function consume(Request $request): JsonResponse
    {
        $secret = (string) config('marketing.bff.shared_secret', '');
        $provided = (string) $request->header('X-Marketing-Proxy-Secret', '');
        if ($secret === '' || $provided === '' || ! hash_equals($secret, $provided)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $alias = (string) $request->input('bucket', '');
        if (! isset(self::BUCKETS[$alias])) {
            return response()->json(['success' => false, 'message' => 'Invalid bucket.'], 422);
        }

        $ip = trim((string) $request->input('client_ip', ''));
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return response()->json(['success' => false, 'message' => 'Invalid client address.'], 422);
        }

        $configBucket = self::BUCKETS[$alias];
        $limits = config("tenants.api.rate_limit.buckets.{$configBucket}")
            ?? config('tenants.api.rate_limit.buckets.default', []);
        $maxAttempts = max(1, (int) ($limits['max_attempts'] ?? 5));
        $decaySeconds = max(1, (int) ($limits['decay_seconds'] ?? 60));
        $key = "api:{$alias}:tplatform:uguest:{$ip}";

        try {
            $accepted = RateLimiter::attempt($key, $maxAttempts, static fn () => true, $decaySeconds);
        } catch (\Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'Please wait a moment and try again.',
            ], 503);
        }

        if ($accepted === false) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please slow down and try again.',
                'retry_after_seconds' => $retryAfter,
            ], 429)->withHeaders([
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $maxAttempts,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        $remaining = max(0, $maxAttempts - RateLimiter::attempts($key));

        return response()->json([
            'success' => true,
            'remaining' => $remaining,
            'limit' => $maxAttempts,
        ])->withHeaders([
            'X-RateLimit-Limit' => (string) $maxAttempts,
            'X-RateLimit-Remaining' => (string) $remaining,
        ]);
    }
}
