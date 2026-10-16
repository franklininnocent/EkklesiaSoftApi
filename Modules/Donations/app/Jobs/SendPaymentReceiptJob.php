<?php

namespace Modules\Donations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\ReceiptDelivery;
use Modules\Donations\Services\DonationNotificationService;

class SendPaymentReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $tenantId, private readonly string $paymentId)
    {
    }

    public function handle(DonationNotificationService $notificationService): void
    {
        $payment = DonationPayment::forTenant($this->tenantId)->with('receipt')->find($this->paymentId);
        if (!$payment) {
            return;
        }

        $delivery = ReceiptDelivery::create([
            'tenant_id' => $this->tenantId,
            'receipt_id' => $payment->receipt?->id,
            'channel' => 'email',
            'recipient' => $payment->payer_email,
            'status' => 'queued',
        ]);

        $notificationService->queue(
            $this->tenantId,
            'receipt.generated',
            'email',
            $payment->payer_email,
            [
                'payment_id' => $payment->id,
                'receipt_number' => $payment->receipt?->receipt_number,
                'amount' => $payment->amount,
            ],
            'payment',
            $payment->id
        );

        $delivery->status = 'sent';
        $delivery->sent_at = now();
        $delivery->save();
    }
}
