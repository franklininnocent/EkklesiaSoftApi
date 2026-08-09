<?php

namespace Modules\MinistriesAssociations\Support;

/**
 * Locked report catalog for Ministries Insights Phase 5.
 */
final class AdminMinistriesReportTypes
{
    public const MODULE_ADOPTION = 'module_adoption';

    public const TENANT_USAGE = 'tenant_usage';

    public const ORGANIZATION_OVERVIEW = 'organization_overview';

    public const MEMBERSHIP_OVERVIEW = 'membership_overview';

    public const LEADERSHIP_OVERVIEW = 'leadership_overview';

    public const INACTIVE_NOT_STARTED = 'inactive_not_started';

    public const FEATURE_ADOPTION = 'feature_adoption';

    public const DATA_HEALTH = 'data_health';

    public const AUDIT = 'audit';

    /**
     * @return list<array{type: string, title: string, description: string, windowed: bool}>
     */
    public static function catalog(): array
    {
        return [
            [
                'type' => self::MODULE_ADOPTION,
                'title' => 'Module Adoption',
                'description' => 'Tenant enablement and adoption funnel (enabled → activated → active → highly engaged).',
                'windowed' => true,
            ],
            [
                'type' => self::TENANT_USAGE,
                'title' => 'Tenant Usage',
                'description' => 'Meaningful actions, active users, and usage trend by tenant.',
                'windowed' => true,
            ],
            [
                'type' => self::ORGANIZATION_OVERVIEW,
                'title' => 'Organization Overview',
                'description' => 'Cross-tenant organization counts by status and sample inventory.',
                'windowed' => false,
            ],
            [
                'type' => self::MEMBERSHIP_OVERVIEW,
                'title' => 'Membership Overview',
                'description' => 'Current memberships by status and source across tenants.',
                'windowed' => false,
            ],
            [
                'type' => self::LEADERSHIP_OVERVIEW,
                'title' => 'Leadership Overview',
                'description' => 'Leadership terms by status across tenants.',
                'windowed' => false,
            ],
            [
                'type' => self::INACTIVE_NOT_STARTED,
                'title' => 'Inactive / Not Started Tenants',
                'description' => 'Enabled tenants that have not started, are inactive, or declining.',
                'windowed' => true,
            ],
            [
                'type' => self::FEATURE_ADOPTION,
                'title' => 'Feature Adoption',
                'description' => 'Percent of enabled tenants with evidence per feature category.',
                'windowed' => true,
            ],
            [
                'type' => self::DATA_HEALTH,
                'title' => 'Data Health',
                'description' => 'Organizations missing members, leadership, or stale activity.',
                'windowed' => false,
            ],
            [
                'type' => self::AUDIT,
                'title' => 'Audit Activity',
                'description' => 'Meaningful Ministries audit events across tenants.',
                'windowed' => true,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (array $row): string => $row['type'], self::catalog());
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::keys(), true);
    }
}
