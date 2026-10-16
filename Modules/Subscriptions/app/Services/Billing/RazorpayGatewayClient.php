<?php

namespace Modules\Subscriptions\Services\Billing;

use Illuminate\Support\Facades\Http;
use Modules\Subscriptions\Models\BillingGatewayConfig;

final class RazorpayGatewayClient
{
    private const TIMEOUT_SECONDS = 15;

    public function createOrder(
        BillingGatewayConfig $config,
        int $amountMinor,
        string $currency,
        string $receipt,
    ): array {
        $keyId = (string) $config->public_key_id;
        $secret = $config->getApiSecretValue();
        if ($keyId === '' || $secret === null) {
            throw new \RuntimeException('Razorpay is not configured.');
        }

        $response = Http::withBasicAuth($keyId, $secret)
            ->timeout(self::TIMEOUT_SECONDS)
            ->post('https://api.razorpay.com/v1/orders', [
                'amount' => $amountMinor,
                'currency' => strtoupper($currency),
                'receipt' => $receipt,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Razorpay order creation failed.');
        }

        return $response->json();
    }

    public function fetchPayment(BillingGatewayConfig $config, string $paymentId): array
    {
        $keyId = (string) $config->public_key_id;
        $secret = $config->getApiSecretValue();
        if ($keyId === '' || $secret === null) {
            throw new \RuntimeException('Razorpay is not configured.');
        }

        $response = Http::withBasicAuth($keyId, $secret)
            ->timeout(self::TIMEOUT_SECONDS)
            ->get('https://api.razorpay.com/v1/payments/'.$paymentId);

        if (! $response->successful()) {
            throw new \RuntimeException('Razorpay payment fetch failed.');
        }

        return $response->json();
    }

    public function verifyCheckoutSignature(string $orderId, string $paymentId, string $signature, string $apiSecret): bool
    {
        $expected = hash_hmac('sha256', $orderId.'|'.$paymentId, $apiSecret);

        return hash_equals($expected, $signature);
    }

    public function verifyWebhookSignature(string $rawBody, string $signature, string $webhookSecret): bool
    {
        $expected = hash_hmac('sha256', $rawBody, $webhookSecret);

        return hash_equals($expected, $signature);
    }

    public function createRefund(BillingGatewayConfig $config, string $paymentId, int $amountMinor): array
    {
        $keyId = (string) $config->public_key_id;
        $secret = $config->getApiSecretValue();
        if ($keyId === '' || $secret === null) {
            throw new \RuntimeException('Razorpay is not configured.');
        }

        $response = Http::withBasicAuth($keyId, $secret)
            ->timeout(self::TIMEOUT_SECONDS)
            ->post('https://api.razorpay.com/v1/payments/'.$paymentId.'/refund', [
                'amount' => $amountMinor,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Razorpay refund failed.');
        }

        return $response->json();
    }

    public function ping(BillingGatewayConfig $config): bool
    {
        $keyId = (string) $config->public_key_id;
        $secret = $config->getApiSecretValue();
        if ($keyId === '' || $secret === null) {
            return false;
        }

        $response = Http::withBasicAuth($keyId, $secret)
            ->timeout(self::TIMEOUT_SECONDS)
            ->get('https://api.razorpay.com/v1/orders', ['count' => 1]);

        return $response->successful();
    }
}
