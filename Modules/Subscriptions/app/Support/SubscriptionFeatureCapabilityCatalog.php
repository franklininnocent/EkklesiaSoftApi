<?php

namespace Modules\Subscriptions\Support;

/**
 * Aggregated customer-facing capability sections for plan comparison UIs.
 */
final class SubscriptionFeatureCapabilityCatalog
{
    /**
     * @return list<array{id: string, title: string, intro: string, rows: list<array{label: string, entitlement: string, customer_note: string}>}>
     */
    public static function sections(): array
    {
        return [
            [
                'id' => 'finance',
                'title' => 'Finance & stewardship',
                'intro' => 'Contributions covers everyday collection. Financial Dashboard adds advanced analytics APIs. Advanced Financial Reporting adds executive and board outputs.',
                'rows' => SubscriptionFinanceCapabilityCatalog::rows(),
            ],
            [
                'id' => 'reporting',
                'title' => 'Stewardship reports',
                'intro' => 'Basic Reports covers core exports. Advanced Reports adds follow-up and books categories in Donations → Reports.',
                'rows' => SubscriptionReportingCapabilityCatalog::rows(),
            ],
            [
                'id' => 'ministries',
                'title' => 'Ministries & associations',
                'intro' => 'Standard Ministries access covers organizations and members. Advanced Ministry Management adds leadership term changes.',
                'rows' => SubscriptionMinistriesCapabilityCatalog::rows(),
            ],
            [
                'id' => 'rbac',
                'title' => 'Roles & permissions',
                'intro' => 'Roles & Permissions covers viewing roles and assigning staff. Advanced adds custom roles and permission design.',
                'rows' => SubscriptionRbacCapabilityCatalog::rows(),
            ],
            [
                'id' => 'pastoral',
                'title' => 'Pastoral care',
                'intro' => 'Mass intentions for scheduling and recording celebrations.',
                'rows' => SubscriptionPastoralCapabilityCatalog::rows(),
            ],
            [
                'id' => 'governance',
                'title' => 'Governance & administration',
                'intro' => 'Plans, audit history, leadership, and diocese workflows beyond core parish records.',
                'rows' => SubscriptionGovernanceCapabilityCatalog::rows(),
            ],
            [
                'id' => 'data',
                'title' => 'Church data',
                'intro' => 'Settings parish data export only (no bulk data upload). Distinct from Donations stewardship report downloads.',
                'rows' => SubscriptionDataCapabilityCatalog::rows(),
            ],
        ];
    }

    /**
     * Entitlement codes with a verified product implementation (used for Super Admin warnings).
     *
     * @return list<string>
     */
    public static function implementedEntitlementCodes(): array
    {
        $codes = [];
        foreach (self::sections() as $section) {
            foreach ($section['rows'] as $row) {
                $codes[] = strtoupper((string) $row['entitlement']);
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @return array<string, string>
     */
    public static function allFeatureDescriptions(): array
    {
        return array_merge(
            SubscriptionFinanceCapabilityCatalog::featureDescriptions(),
            SubscriptionReportingCapabilityCatalog::featureDescriptions(),
            SubscriptionMinistriesCapabilityCatalog::featureDescriptions(),
            SubscriptionRbacCapabilityCatalog::featureDescriptions(),
            SubscriptionPastoralCapabilityCatalog::featureDescriptions(),
            SubscriptionGovernanceCapabilityCatalog::featureDescriptions(),
            SubscriptionDataCapabilityCatalog::featureDescriptions(),
        );
    }
}
