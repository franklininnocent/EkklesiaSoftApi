<?php

namespace Modules\Subscriptions\Support;

final class SubscriptionPastoralCapabilityCatalog
{
    /**
     * @return list<array{label: string, entitlement: string, customer_note: string}>
     */
    public static function rows(): array
    {
        return [
            [
                'label' => 'Mass intentions (schedule, record, fulfil)',
                'entitlement' => 'MASS_INTENTIONS',
                'customer_note' => 'Included on Professional and Enterprise plans when published in the catalog.',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function featureDescriptions(): array
    {
        return [
            'MASS_INTENTIONS' => 'Record Mass intentions, schedule celebrations, and track fulfilment for your parish.',
        ];
    }
}
