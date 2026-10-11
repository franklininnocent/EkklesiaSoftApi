<?php

namespace Modules\Tenants\Support;

use Illuminate\Http\Request;

/**
 * Method-aware subscription route classification (recovery, read-only POST, restricted generation).
 */
final class SubscriptionAccessRoutePolicy
{
    /**
     * @param  list<string>  $recoveryPatterns
     * @param  list<string>  $readOnlyPostPatterns
     * @param  list<string>  $restrictedGenerationPatterns
     */
    public function __construct(
        private readonly array $recoveryPatterns = [],
        private readonly array $readOnlyPostPatterns = [],
        private readonly array $restrictedGenerationPatterns = [],
    ) {}

    public static function fromConfig(): self
    {
        $sub = config('tenants.subscription', []);

        return new self(
            array_values($sub['recovery_allowlist'] ?? []),
            array_values($sub['read_only_post_allowlist'] ?? []),
            array_values($sub['restricted_generation_paths'] ?? []),
        );
    }

    /**
     * Legacy write allowlist (used when view-only/locked enforcement is disabled).
     */
    public static function legacyWriteAllowlist(): SubscriptionRouteAllowlist
    {
        return new SubscriptionRouteAllowlist(
            array_values(config('tenants.subscription.read_only_write_allowlist', []))
        );
    }

    public function isRecovery(Request $request): bool
    {
        return $this->matchesAny($request, $this->recoveryPatterns);
    }

    public function isReadOnlyPost(Request $request): bool
    {
        if (! in_array($request->method(), ['POST'], true)) {
            return false;
        }

        return $this->matchesAny($request, $this->readOnlyPostPatterns);
    }

    public function isRestrictedGeneration(Request $request): bool
    {
        return $this->matchesAny($request, $this->restrictedGenerationPatterns);
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAny(Request $request, array $patterns): bool
    {
        $path = trim($request->path(), '/');
        $method = strtoupper($request->method());

        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }

            $patternMethod = null;
            if (str_contains($pattern, ':')) {
                [$patternMethod, $pathPattern] = explode(':', $pattern, 2);
                $patternMethod = strtoupper(trim($patternMethod));
                $pattern = trim($pathPattern, '/');
            } else {
                $pattern = trim($pattern, '/');
            }

            if ($patternMethod !== null && $patternMethod !== $method) {
                continue;
            }

            if ($this->pathMatches($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function pathMatches(string $path, string $pattern): bool
    {
        if ($pattern === $path) {
            return true;
        }

        if (str_ends_with($pattern, '*')) {
            $prefix = rtrim($pattern, '*');

            return str_starts_with($path, $prefix);
        }

        if (str_contains($pattern, '*')) {
            $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/';

            return (bool) preg_match($regex, $path);
        }

        return false;
    }
}
