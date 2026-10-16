<?php

namespace Modules\Subscriptions\Support;

/**
 * Customer-facing finance / stewardship capability matrix (display + aligned with route middleware).
 */
final class SubscriptionFinanceCapabilityCatalog
{
    /**
     * @return list<array{label: string, entitlement: string, customer_note: string}>
     */
    public static function rows(): array
    {
        return [
            [
                'label' => 'Record contributions, issue receipts, and run day-to-day collection',
                'entitlement' => 'CONTRIBUTIONS',
                'customer_note' => 'Core stewardship module access (with staff permission). Included from Starter upward.',
            ],
            [
                'label' => 'Financial overview dashboard (collections, outstanding, trends)',
                'entitlement' => 'CONTRIBUTIONS',
                'customer_note' => 'The main Donations overview uses standard dashboard APIs available with Contributions.',
            ],
            [
                'label' => 'Financial command center, parish rollup, and collection forecast analytics',
                'entitlement' => 'FINANCIAL_DASHBOARD',
                'customer_note' => 'Requires Financial Dashboard on your plan (Standard and above in launch plans).',
            ],
            [
                'label' => 'Executive financial summary, drill-down KPIs, and board-ready prints',
                'entitlement' => 'ADVANCED_FINANCIAL_REPORTING',
                'customer_note' => 'Professional-tier financial reporting; also requires Advanced Reports in the plan catalog.',
            ],
            [
                'label' => 'Follow-up and books stewardship exports (who owes, participation, allocations, disbursements)',
                'entitlement' => 'ADVANCED_REPORTS',
                'customer_note' => 'Advanced Reports on your plan unlocks these downloadable reports in Donations → Reports.',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function featureDescriptions(): array
    {
        return [
            'CONTRIBUTIONS' => 'Record family contributions, run collection day, manage payments and receipts, and view the parish financial overview dashboard.',
            'FINANCIAL_DASHBOARD' => 'Adds financial command center, diocese rollup, and collection forecast analytics APIs beyond the standard overview dashboard (requires Contributions).',
            'ADVANCED_FINANCIAL_REPORTING' => 'Executive financial summary, interactive drill-down, parish comparison, and board print reports in Donations (requires Contributions and Advanced Reports).',
        ];
    }
}
