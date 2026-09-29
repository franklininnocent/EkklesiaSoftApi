<?php

namespace Modules\MinistriesAssociations\Support;

/**
 * Locked adoption / usage / health definitions for Ekklesia platform analytics.
 * UI copy and aggregation services must mirror this class — do not invent alternate labels.
 */
final class AdminMinistriesDefinitions
{
    public const FEATURE_KEY = 'ministries_associations';

    public const DEFAULT_ACTIVITY_WINDOW_DAYS = 30;

    public const HIGHLY_ENGAGED_MIN_EVENTS = 10;

    public const HIGHLY_ENGAGED_MIN_FEATURE_CATEGORIES = 2;

    public const DECLINING_PRIOR_MIN_EVENTS = 5;

    public const DECLINING_RATIO_MAX = 0.5;

    public const STALE_ORG_DAYS = 90;

    /** @var list<string> */
    public const MEANINGFUL_EVENTS = [
        'organization.created',
        'organization.updated',
        'organization.status_changed',
        'organization.deleted',
        'organization.restored',
        'membership.enrolled',
        'membership.status_changed',
        'membership.re_enrolled',
        'leadership.assigned',
        'leadership.handover',
        'leadership.terminated',
        'category.created',
        'category.updated',
        'category.status_changed',
        'category.defaults_seeded',
        'type.created',
        'type.updated',
        'type.status_changed',
        'type.defaults_seeded',
        'position.created',
        'position.updated',
        'position.status_changed',
        'position.defaults_seeded',
        'guest_member.created',
        'guest_member.updated',
        'guest_member.linked_to_parishioner',
    ];

    /** @var array<string, list<string>> */
    public const FEATURE_EVENT_MAP = [
        'organizations' => [
            'organization.created',
            'organization.updated',
            'organization.status_changed',
            'organization.deleted',
            'organization.restored',
        ],
        'members' => [
            'membership.enrolled',
            'membership.status_changed',
            'membership.re_enrolled',
        ],
        'leadership' => [
            'leadership.assigned',
            'leadership.handover',
            'leadership.terminated',
        ],
        'taxonomies' => [
            'category.created',
            'category.updated',
            'category.status_changed',
            'category.defaults_seeded',
            'type.created',
            'type.updated',
            'type.status_changed',
            'type.defaults_seeded',
            'position.created',
            'position.updated',
            'position.status_changed',
            'position.defaults_seeded',
        ],
        'guests' => [
            'guest_member.created',
            'guest_member.updated',
            'guest_member.linked_to_parishioner',
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        return [
            'feature_key' => self::FEATURE_KEY,
            'default_activity_window_days' => self::DEFAULT_ACTIVITY_WINDOW_DAYS,
            'module_status' => [
                'disabled' => 'Tenant lacks ministries_associations in features.',
                'enabled' => 'Tenant has ministries_associations entitlement.',
            ],
            'adoption_status' => [
                'disabled' => 'Module status is disabled.',
                'not_started' => 'Enabled and organizations_count = 0.',
                'activated' => 'Enabled and organizations_count >= 1 (activation = earliest org created_at).',
                'active' => 'Activated and >= 1 meaningful audit event in the activity window.',
                'highly_engaged' => 'Activated and in window: >= '
                    .self::HIGHLY_ENGAGED_MIN_EVENTS
                    .' meaningful events and >= '
                    .self::HIGHLY_ENGAGED_MIN_FEATURE_CATEGORIES
                    .' feature categories used.',
                'inactive' => 'Activated and 0 meaningful events in the activity window.',
                'declining' => 'Activated, prior window meaningful count >= '
                    .self::DECLINING_PRIOR_MIN_EVENTS
                    .' and current/prior <= '
                    .self::DECLINING_RATIO_MAX
                    .'.',
            ],
            'meaningful_events' => self::MEANINGFUL_EVENTS,
            'feature_categories' => array_keys(self::FEATURE_EVENT_MAP),
            'health_indicators' => [
                'orgs_without_active_members',
                'active_orgs_without_leadership',
                'stale_active_orgs',
            ],
            'health_score' => 'Not used in MVP. Prefer health_flag (ok|attention|critical) from indicators.',
            'unavailable_vs_zero' => 'Unknown metrics must be null / available:false — never coerced to 0.',
        ];
    }
}
