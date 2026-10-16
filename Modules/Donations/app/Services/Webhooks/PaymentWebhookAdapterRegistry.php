<?php

namespace Modules\Donations\Services\Webhooks;

use Modules\Donations\Services\Webhooks\Adapters\GenericWebhookAdapter;
use Modules\Donations\Services\Webhooks\Contracts\PaymentWebhookAdapterInterface;

class PaymentWebhookAdapterRegistry
{
    public function resolve(string $provider): PaymentWebhookAdapterInterface
    {
        return match (strtolower($provider)) {
            default => new GenericWebhookAdapter(),
        };
    }
}
