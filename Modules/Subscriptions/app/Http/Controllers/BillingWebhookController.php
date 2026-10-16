<?php

namespace Modules\Subscriptions\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Subscriptions\Jobs\ProcessBillingWebhookJob;
use Modules\Subscriptions\Models\BillingWebhookEvent;
use Modules\Subscriptions\Services\Billing\BillingWebhookProcessor;
use Modules\Subscriptions\Services\Billing\RazorpayGatewayClient;

class BillingWebhookController extends Controller
{
    public function __construct(
        private readonly BillingWebhookProcessor $processor,
    ) {}

    public function razorpay(Request $request): Response
    {
        $raw = $request->getContent();
        $signature = (string) $request->header('X-Razorpay-Signature', '');
        $payload = json_decode($raw, true);
        $eventId = is_array($payload) ? (string) ($payload['id'] ?? '') : '';
        $eventType = is_array($payload) ? (string) ($payload['event'] ?? '') : '';

        if ($eventType !== '' && str_starts_with($eventType, 'subscription.')) {
            $environment = $this->detectEnvironment($payload);
            $config = $this->processor->resolveWebhookSecret($environment, $raw, $signature);
            if ($config === null) {
                return response('', 400);
            }
            if ($eventId !== '') {
                $existing = BillingWebhookEvent::query()->where('provider_event_id', $eventId)->first();
                if ($existing !== null) {
                    return response('', 200);
                }
            }
            BillingWebhookEvent::query()->create([
                'provider_event_id' => $eventId !== '' ? $eventId : 'unknown_'.sha1($raw),
                'environment' => $environment,
                'event_type' => $eventType,
                'signature_valid' => true,
                'processing_status' => 'ignored_subscription_product',
                'payload_summary' => null,
                'available_at' => now(),
            ]);

            return response('', 200);
        }

        $environment = $this->detectEnvironment($payload);
        $config = $this->processor->resolveWebhookSecret($environment, $raw, $signature);
        $valid = $config !== null;

        if ($eventId !== '') {
            $existing = BillingWebhookEvent::query()->where('provider_event_id', $eventId)->first();
            if ($existing !== null) {
                return response('', 200);
            }
        }

        $paymentSummary = null;
        if (is_array($payload)) {
            $entity = $payload['payload']['payment']['entity'] ?? $payload['payload']['order']['entity'] ?? null;
            if (is_array($entity)) {
                $paymentSummary = ['payment' => $entity];
            }
        }

        $event = BillingWebhookEvent::query()->create([
            'provider_event_id' => $eventId !== '' ? $eventId : 'unknown_'.sha1($raw),
            'environment' => $environment,
            'event_type' => $eventType,
            'signature_valid' => $valid,
            'processing_status' => $valid ? BillingWebhookEvent::STATUS_ACCEPTED : 'rejected_signature',
            'payload_summary' => $paymentSummary,
            'available_at' => now(),
        ]);

        if ($valid) {
            ProcessBillingWebhookJob::dispatch($event->id);
        }

        return response('', $valid ? 200 : 400);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function detectEnvironment(?array $payload): string
    {
        $live = BillingWebhookEvent::query()->getConnection()
            ->table('billing_gateway_configs')
            ->where('environment', 'live')
            ->where('live_checkout_enabled', true)
            ->exists();

        return $live ? 'live' : 'test';
    }
}
