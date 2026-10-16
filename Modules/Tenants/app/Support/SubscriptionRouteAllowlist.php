<?php

namespace Modules\Tenants\Support;

use Illuminate\Http\Request;

/**
 * Path-pattern allowlist for subscription read-only write exceptions.
 */
final class SubscriptionRouteAllowlist
{
    /**
     * @param  list<string>  $patterns
     */
    public function __construct(
        private readonly array $patterns = [],
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(array_values(config('tenants.subscription.read_only_write_allowlist', [])));
    }

    public function allows(Request $request): bool
    {
        $path = trim($request->path(), '/');

        foreach ($this->patterns as $pattern) {
            $pattern = trim((string) $pattern, '/');
            if ($pattern === '') {
                continue;
            }

            if ($this->matches($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $path, string $pattern): bool
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
