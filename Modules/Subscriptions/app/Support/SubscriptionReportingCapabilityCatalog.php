<?php

namespace Modules\Subscriptions\Support;

final class SubscriptionReportingCapabilityCatalog
{
    /**
     * @return list<array{label: string, entitlement: string, customer_note: string}>
     */
    public static function rows(): array
    {
        return [
            [
                'label' => 'Core stewardship report exports (payments, offerings, receipts, family giving)',
                'entitlement' => 'BASIC_REPORTS',
                'customer_note' => 'Included with Basic Reports; still requires Contributions and report permissions.',
            ],
            [
                'label' => 'Follow-up and books reports (outstanding balances, participation, allocations, disbursements)',
                'entitlement' => 'ADVANCED_REPORTS',
                'customer_note' => 'Unlocked with Advanced Reports (Standard+). Distinct from Professional executive financial reporting.',
            ],
            [
                'label' => 'Executive and board-level financial analytics',
                'entitlement' => 'ADVANCED_FINANCIAL_REPORTING',
                'customer_note' => 'See Advanced Financial Reporting under Finance capabilities.',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function featureDescriptions(): array
    {
        return [
            'BASIC_REPORTS' => 'Foundation reporting tier: core downloadable stewardship reports in Donations when staff have report access (with Contributions).',
            'ADVANCED_REPORTS' => 'Adds follow-up and books stewardship report exports in Donations → Reports; required dependency for Advanced Financial Reporting.',
        ];
    }
}
