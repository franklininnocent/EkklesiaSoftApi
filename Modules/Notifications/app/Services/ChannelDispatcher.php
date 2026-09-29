<?php

namespace Modules\Notifications\Services;

use Modules\Notifications\Jobs\DeliverNotificationMailJob;
use Modules\Notifications\Models\NotificationDefinition;
use Modules\Notifications\Models\NotificationDelivery;
use Modules\Notifications\Models\UserNotification;

class ChannelDispatcher
{
    public function dispatch(UserNotification $userNotification, NotificationDefinition $definition): void
    {
        $channels = $definition->channels ?? ['in_app' => true, 'email' => false];

        if ($channels['in_app'] ?? true) {
            NotificationDelivery::query()->firstOrCreate(
                ['user_notification_id' => $userNotification->id, 'channel' => 'in_app'],
                ['status' => 'sent']
            );
        }

        if (($channels['email'] ?? false) && config('notifications.mail_owner') === 'platform') {
            $delivery = NotificationDelivery::query()->firstOrCreate(
                ['user_notification_id' => $userNotification->id, 'channel' => 'email'],
                ['status' => 'queued']
            );

            if ($delivery->status === 'queued') {
                DeliverNotificationMailJob::dispatch($delivery->id)->onQueue('notifications');
            }
        }
    }
}
