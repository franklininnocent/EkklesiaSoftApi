<?php

namespace Modules\Subscriptions\Support;

final class SubscriptionMinistriesCapabilityCatalog
{
    /**
     * @return list<array{label: string, entitlement: string, customer_note: string}>
     */
    public static function rows(): array
    {
        return [
            [
                'label' => 'Open Ministries & Associations and manage organizations and members',
                'entitlement' => 'MINISTRIES',
                'customer_note' => 'Module access for parishes on a plan that includes Ministries & Associations.',
            ],
            [
                'label' => 'View current ministry leadership and leadership history',
                'entitlement' => 'MINISTRIES',
                'customer_note' => 'Read-only leadership timeline is included with standard Ministries access.',
            ],
            [
                'label' => 'Assign, hand over, edit, or end leadership terms',
                'entitlement' => 'ADVANCED_MINISTRY_MANAGEMENT',
                'customer_note' => 'Requires Advanced Ministry Management (Professional in launch plans) plus manage-leadership permission.',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function featureDescriptions(): array
    {
        return [
            'MINISTRIES' => 'Ministries and associations: organizations, memberships, guest members, and read-only leadership information.',
            'ADVANCED_MINISTRY_MANAGEMENT' => 'Everything in Ministries & Associations, plus assign and manage leadership terms (handover, updates, and end of term).',
        ];
    }
}
