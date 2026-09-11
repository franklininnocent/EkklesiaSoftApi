<?php

namespace Modules\Tenants\DefaultSeeds;

use Modules\Tenants\Contracts\TenantDefaultSeedDefinition;
use RuntimeException;

class DefaultSeedRegistry
{
    /** @var array<string, TenantDefaultSeedDefinition> */
    private array $definitions = [];

    public function register(TenantDefaultSeedDefinition $definition): void
    {
        $id = $definition->id();
        if (isset($this->definitions[$id])) {
            throw new RuntimeException("Duplicate default seed definition: {$id}");
        }

        $this->definitions[$id] = $definition;
    }

    public function get(string $id): TenantDefaultSeedDefinition
    {
        if (! isset($this->definitions[$id])) {
            throw new RuntimeException("Unknown default seed: {$id}");
        }

        return $this->definitions[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->definitions[$id]);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->definitions);
    }

    /**
     * @return list<TenantDefaultSeedDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /**
     * @param  list<string>  $ids
     * @return list<TenantDefaultSeedDefinition>
     */
    public function many(array $ids): array
    {
        $resolved = [];
        foreach ($ids as $id) {
            $resolved[] = $this->get($id);
        }

        return $resolved;
    }
}
