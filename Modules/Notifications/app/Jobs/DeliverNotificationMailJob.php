<?php

namespace Modules\Notifications\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Authentication\Models\User;
use Modules\Notifications\Models\NotificationDelivery;

class DeliverNotificationMailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(public string $deliveryId)
    {
        $this->onQueue('notifications');
    }

    public function backoff(): array
    {
        return [30, 60, 120, 300, 600];
    }

    public function handle(): void
    {
        $delivery = NotificationDelivery::query()->with(['userNotification.event'])->find($this->deliveryId);
        if ($delivery === null || ! in_array($delivery->status, ['queued', 'failed'], true)) {
            return;
        }

        $userNotification = $delivery->userNotification;
        $event = $userNotification?->event;
        $user = User::query()->find($userNotification?->user_id);

        if ($event === null || $user === null || ! $user->active || ! $user->email) {
            $delivery->update(['status' => 'skipped', 'last_error_code' => 'no_recipient']);

            return;
        }

        try {
            Mail::raw($event->body, function ($message) use ($user, $event): void {
                $message->to($user->email)->subject($event->title);
            });

            $delivery->update([
                'status' => 'sent',
                'attempts' => $delivery->attempts + 1,
            ]);
        } catch (\Throwable $e) {
            $delivery->update([
                'status' => 'failed',
                'attempts' => $delivery->attempts + 1,
                'last_error_code' => 'mail_failed',
                'next_retry_at' => now()->addSeconds($this->backoff()[min($delivery->attempts, 4)] ?? 600),
            ]);

            Log::warning('Notification mail delivery failed', [
                'delivery_id' => $delivery->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
