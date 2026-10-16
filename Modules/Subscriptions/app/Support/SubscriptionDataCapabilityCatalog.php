<?php

namespace Modules\Subscriptions\Support;

final class SubscriptionDataCapabilityCatalog
{
    /**
     * @return list<array{label: string, entitlement: string, customer_note: string}>
     */
    public static function rows(): array
    {
        return [
            [
                'label' => 'Church data export (Settings → Data export)',
                'entitlement' => 'IMPORT_EXPORT',
                'customer_note' => 'Bulk parish data export via Settings. Distinct from stewardship report downloads in Donations.',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function featureDescriptions(): array
    {
        return [
            'IMPORT_EXPORT' => 'Export parish business data from Settings → Data export (ZIP/CSV). Import is not offered in the product today.',
        ];
    }
}
