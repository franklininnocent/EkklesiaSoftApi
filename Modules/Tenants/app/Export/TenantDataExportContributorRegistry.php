<?php

namespace Modules\Tenants\Export;

use Modules\Tenants\Contracts\TenantDataExportContributor;
use RuntimeException;

/**
 * Registry of export module contributors (tagged / registered in service providers).
 */
class TenantDataExportContributorRegistry
{
    /** @var array<string, TenantDataExportContributor> */
    private array $contributors = [];

    public function register(TenantDataExportContributor $contributor): void
    {
        $key = $contributor->key();
        if (isset($this->contributors[$key])) {
            throw new RuntimeException("Duplicate tenant data export contributor: {$key}");
        }

        $this->contributors[$key] = $contributor;
    }

    public function get(string $key): TenantDataExportContributor
    {
        if (! isset($this->contributors[$key])) {
            throw new RuntimeException("Unknown export module: {$key}");
        }

        return $this->contributors[$key];
    }

    public function has(string $key): bool
    {
        return isset($this->contributors[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->contributors);
    }

    /**
     * @return list<TenantDataExportContributor>
     */
    public function all(): array
    {
        return array_values($this->contributors);
    }

    /**
     * @param  list<string>  $keys
     * @return list<TenantDataExportContributor>
     */
    public function many(array $keys): array
    {
        $selected = [];
        foreach ($keys as $key) {
            $selected[] = $this->get($key);
        }

        return $selected;
    }

    /**
     * Catalog payload for GET /export/modules.
     *
     * @return list<array{key: string, label: string, default_selected: bool, estimated_records: int}>
     */
    public function catalog(int $tenantId): array
    {
        $items = [];
        foreach ($this->contributors as $contributor) {
            $items[] = [
                'key' => $contributor->key(),
                'label' => $contributor->label(),
                'default_selected' => $contributor->defaultSelected(),
                'estimated_records' => $contributor->estimateCount($tenantId),
            ];
        }

        return $items;
    }
}
