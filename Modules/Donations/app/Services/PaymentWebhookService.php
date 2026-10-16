<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\PaymentGatewayWebhookEvent;
use Modules\Donations\Services\Webhooks\PaymentWebhookAdapterRegistry;

class PaymentWebhookService
{
    public function __construct(private readonly PaymentWebhookAdapterRegistry $adapterRegistry)
    {
    }

    public function validateSignature(string $provider, array $payload, ?string $signature = null): bool
    {
        $adapter = $this->adapterRegistry->resolve($provider);
        $secret = config("donations.webhooks.providers.{$provider}.secret", config('donations.webhooks.secret'));

        return $adapter->validateSignature($payload, $signature, $secret);
    }

    public function receive(string $provider, array $payload, ?string $signature = null): PaymentGatewayWebhookEvent
    {
        $adapter = $this->adapterRegistry->resolve($provider);
        $normalized = $adapter->normalize($payload);

        return PaymentGatewayWebhookEvent::create([
            'provider' => $provider,
            'event_type' => $normalized['event_type'],
            'event_id' => $normalized['event_id'],
            'signature' => $signature,
            'status' => 'received',
            'payload' => $normalized['payload'],
        ]);
    }

    public function process(PaymentGatewayWebhookEvent $event): PaymentGatewayWebhookEvent
    {
        try {
            // Contract stub: normalize provider payload and map to payment updates.
            // Full provider-specific adapters will be added in payment gateway phase.
            $event->status = 'processed';
            $event->processed_at = now();
            $event->error_message = null;
            $event->save();
        } catch (\Throwable $exception) {
            $event->status = 'failed';
            $event->error_message = $exception->getMessage();
            $event->save();
        }

        return $event;
    }
}
