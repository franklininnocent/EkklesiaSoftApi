<?php

namespace Modules\Tenants\Contracts;

/**
 * Server-side default seed registry entry. Frontend receives only stable IDs.
 */
interface TenantDefaultSeedDefinition
{
    public function id(): string;

    public function module(): string;

    public function moduleLabel(): string;

    public function displayName(): string;

    public function description(): string;

    public function sortOrder(): int;

    public function requiredPermission(): string;

    /** Subscription / plan feature key (e.g. donations, ministries_associations). */
    public function featureKey(): string;

    /**
     * @return list<string>
     */
    public function dependsOn(): array;

    /**
     * Catalog payload for one seeder (metadata + status counts).
     *
     * @return array<string, mixed>
     */
    public function catalog(int $tenantId): array;

    /**
     * Execute ADD_MISSING_DEFAULTS for this tenant.
     *
     * @return array<string, mixed>
     */
    public function execute(int $tenantId, ?int $userId): array;
}
