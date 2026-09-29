<?php

namespace Modules\Tenants\Contracts;

use Modules\Tenants\Models\Tenant;

/**
 * Plan-driven entitlement decisions for a tenant.
 *
 * Implemented by the Subscriptions module. The Tenants module computes its
 * legacy decision first and passes it in so the implementation can run in
 * legacy / shadow / enforce mode without the Tenants module depending on it.
 */
interface TenantEntitlementGate
{
    /**
     * Decide access for a legacy feature key (e.g. "donations", "ministries_associations").
     */
    public function decideLegacyFeature(Tenant $tenant, string $legacyKey, bool $legacyDecision): bool;

    /**
     * Whether a catalog feature code (e.g. "CONTRIBUTIONS") is enabled for the tenant.
     */
    public function allows(Tenant $tenant, string $featureCode): bool;

    /**
     * Resolved numeric limit for a catalog limit/quota feature; null means unlimited.
     */
    public function limit(Tenant $tenant, string $featureCode): ?int;

    /**
     * Drop any cached entitlements for the tenant.
     */
    public function forget(Tenant|int $tenant): void;

    /**
     * Plan summary and entitlements version for the tenant subscription endpoints, plus usage
     * against limits when $withUsage (only for callers already authorised to view the subscription).
     *
     * @return array{plan: array<string, mixed>|null, entitlements_version: string, engine_mode: string, limits?: list<array<string, mixed>>}
     */
    public function accessSummary(Tenant $tenant, bool $withUsage = false): array;
}
