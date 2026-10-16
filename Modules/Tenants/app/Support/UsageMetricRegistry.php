<?php

namespace Modules\Tenants\Support;

use Modules\Tenants\Contracts\UsageMetricProvider;

/**
 * Registry of per-metric usage providers contributed by owning modules.
 */
final class UsageMetricRegistry
{
    /** @var array<string, UsageMetricProvider> */
    private array $providers = [];

    public function register(UsageMetricProvider $provider): void
    {
        $this->providers[strtoupper($provider->metricCode())] = $provider;
    }

    public function has(string $metricCode): bool
    {
        return isset($this->providers[strtoupper($metricCode)]);
    }

    public function get(string $metricCode): ?UsageMetricProvider
    {
        return $this->providers[strtoupper($metricCode)] ?? null;
    }

    /**
     * @return array<string, UsageMetricProvider>
     */
    public function all(): array
    {
        return $this->providers;
    }
}
