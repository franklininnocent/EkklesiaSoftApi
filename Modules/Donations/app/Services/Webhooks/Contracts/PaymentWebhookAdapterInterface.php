<?php

namespace Modules\Donations\Services\Webhooks\Contracts;

interface PaymentWebhookAdapterInterface
{
    public function normalize(array $payload): array;

    public function validateSignature(array $payload, ?string $signature, ?string $secret): bool;
}
