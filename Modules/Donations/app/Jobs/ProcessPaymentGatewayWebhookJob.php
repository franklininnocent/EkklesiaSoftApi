<?php

namespace Modules\Donations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Donations\Models\PaymentGatewayWebhookEvent;
use Modules\Donations\Services\PaymentWebhookService;

class ProcessPaymentGatewayWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly string $eventId)
    {
    }

    public function handle(PaymentWebhookService $webhookService): void
    {
        $event = PaymentGatewayWebhookEvent::query()->find($this->eventId);
        if (!$event) {
            return;
        }

        $webhookService->process($event);
    }
}
