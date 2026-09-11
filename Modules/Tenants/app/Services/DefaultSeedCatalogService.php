<?php

namespace Modules\Tenants\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Authentication\Models\User;
use Modules\Tenants\DefaultSeeds\DefaultSeedRegistry;
use Modules\Tenants\DefaultSeeds\DefaultSeedStatus;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;

class DefaultSeedCatalogService
{
    public function __construct(
        private readonly DefaultSeedRegistry $registry,
        private readonly DefaultSeedAuthorizationService $authorization,
    ) {
    }

    /**
     * @return array{summary: array<string, int>, seeders: list<array<string, mixed>>}
     */
    public function catalog(User $user, TenantContext $context): array
    {
        $tenantId = $this->authorization->assertCanAccessCatalog($user, $context);
        $tenant = Tenant::query()->findOrFail($tenantId);

        $seeders = [];
        foreach ($this->registry->all() as $definition) {
            if (! $this->authorization->canRunDefinition($user, $tenant, $definition)) {
                continue;
            }

            $item = $definition->catalog($tenantId);
            $item['can_run'] = true;
            $seeders[] = $item;
        }

        usort($seeders, fn (array $a, array $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));

        return [
            'summary' => $this->summarize($seeders),
            'seeders' => $seeders,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $seeders
     * @return array<string, int>
     */
    private function summarize(array $seeders): array
    {
        $summary = [
            'available' => 0,
            'partially_initialized' => 0,
            'initialized' => 0,
            'total' => count($seeders),
        ];

        foreach ($seeders as $row) {
            $status = $row['status'] ?? DefaultSeedStatus::UNAVAILABLE;
            if ($status === DefaultSeedStatus::AVAILABLE) {
                $summary['available']++;
            } elseif ($status === DefaultSeedStatus::PARTIALLY_INITIALIZED) {
                $summary['partially_initialized']++;
            } elseif ($status === DefaultSeedStatus::INITIALIZED) {
                $summary['initialized']++;
            }
        }

        return $summary;
    }
}
