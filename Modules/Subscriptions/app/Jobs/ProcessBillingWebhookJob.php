<?php

namespace Modules\Subscriptions\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Subscriptions\Models\BillingWebhookEvent;
use Modules\Subscriptions\Services\Billing\BillingWebhookProcessor;

class ProcessBillingWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $eventId) {}

    public function handle(BillingWebhookProcessor $processor): void
    {
        $event = BillingWebhookEvent::query()->find($this->eventId);
        if ($event === null) {
            return;
        }

        $processor->process($event);
    }
}
