<?php

namespace Modules\Subscriptions\Support;

/**
 * Governance and administration capabilities shown in plan comparison (verified implementations).
 */
final class SubscriptionGovernanceCapabilityCatalog
{
    /**
     * @return list<array{label: string, entitlement: string, customer_note: string}>
     */
    public static function rows(): array
    {
        return [
            [
                'label' => 'Contribution plans, assignments, and scheduled dues generation',
                'entitlement' => 'CONTRIBUTION_PLANS',
                'customer_note' => 'Standard and above; requires Contributions and donations manage permission for changes.',
            ],
            [
                'label' => 'Stewardship audit log (who changed donations records)',
                'entitlement' => 'AUDIT_LOG',
                'customer_note' => 'Standard and above; staff still need audit log view permission.',
            ],
            [
                'label' => 'Manage parish leadership records (assign, update, end terms)',
                'entitlement' => 'CHURCH_LEADERSHIP',
                'customer_note' => 'Mutations require this entitlement; viewing existing leadership history remains available where the product allows reads.',
            ],
            [
                'label' => 'Submit diocese and bishop update requests',
                'entitlement' => 'DIOCESE_BISHOPS',
                'customer_note' => 'Write requests require this entitlement; directory and read surfaces follow existing RBAC.',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function featureDescriptions(): array
    {
        return [
            'CONTRIBUTION_PLANS' => 'Recurring contribution plans, family assignments, and scheduled dues generation in Donations (requires Contributions).',
            'AUDIT_LOG' => 'View stewardship change history in Donations and other audit surfaces your plan includes.',
            'CHURCH_LEADERSHIP' => 'Create and maintain parish leadership assignments on the church profile (mutations gated; reads preserved per product rules).',
            'DIOCESE_BISHOPS' => 'Submit structured update requests for diocese and bishop records (mutations gated).',
        ];
    }
}
