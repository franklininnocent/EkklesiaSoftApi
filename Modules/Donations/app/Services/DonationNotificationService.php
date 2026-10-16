<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\DonationNotificationLog;

class DonationNotificationService
{
    public function queue(
        int $tenantId,
        string $type,
        string $channel,
        ?string $recipient,
        array $payload = [],
        ?string $targetType = null,
        ?string $targetId = null
    ): DonationNotificationLog {
        return DonationNotificationLog::create([
            'tenant_id' => $tenantId,
            'notification_type' => $type,
            'channel' => $channel,
            'recipient' => $recipient,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'status' => 'queued',
            'payload' => $payload,
        ]);
    }

    public function markSent(DonationNotificationLog $log, array $meta = []): DonationNotificationLog
    {
        $payload = $log->payload ?? [];
        if (!empty($meta)) {
            $payload['delivery_meta'] = array_merge($payload['delivery_meta'] ?? [], $meta);
            $log->payload = $payload;
        }

        $log->status = 'sent';
        $log->sent_at = now();
        $log->save();

        return $log;
    }

    public function markFailed(DonationNotificationLog $log, string $errorMessage): DonationNotificationLog
    {
        $log->status = 'failed';
        $log->error_message = $errorMessage;
        $log->save();

        return $log;
    }
}
