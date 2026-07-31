<?php

namespace Modules\Donations\Services\Webhooks\Adapters;

use Modules\Donations\Services\Webhooks\Contracts\PaymentWebhookAdapterInterface;

class GenericWebhookAdapter implements PaymentWebhookAdapterInterface
{
    public function normalize(array $payload): array
    {
        return [
            'event_type' => (string) ($payload['type'] ?? $payload['event_type'] ?? 'unknown'),
            'event_id' => (string) ($payload['id'] ?? $payload['event_id'] ?? ''),
            'payload' => $payload,
        ];
    }

    public function validateSignature(array $payload, ?string $signature, ?string $secret): bool
    {
        if (empty($secret)) {
            return true;
        }

        return hash_equals($secret, (string) $signature);
    }
}
