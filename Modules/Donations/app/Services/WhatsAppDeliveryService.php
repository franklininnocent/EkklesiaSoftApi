<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\DonationNotificationLog;

class WhatsAppDeliveryService
{
    public function __construct(
        private readonly WhatsAppBusinessApiService $businessApiService,
        private readonly DonationNotificationService $notificationService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function deliverPending(int $tenantId, int $limit = 25): array
    {
        $logs = DonationNotificationLog::forTenant($tenantId)
            ->where('channel', 'whatsapp')
            ->where('status', 'queued')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $results = [];
        foreach ($logs as $log) {
            $results[] = $this->deliverLog($log);
        }

        return [
            'processed' => count($results),
            'delivered' => count(array_filter($results, fn (array $row): bool => ($row['status'] ?? '') === 'sent')),
            'results' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deliverLog(DonationNotificationLog $log): array
    {
        $payload = $log->payload ?? [];
        $phone = (string) ($log->recipient ?? '');
        $message = (string) ($payload['message'] ?? '');

        if ($phone === '' || $message === '') {
            $this->notificationService->markFailed($log, 'Missing recipient phone or message payload.');

            return [
                'notification_id' => $log->id,
                'status' => 'failed',
                'delivery_mode' => 'invalid_payload',
            ];
        }

        $delivery = $this->businessApiService->sendTextMessage($phone, $message);
        $payload['delivery'] = $delivery;
        $payload['whatsapp_url'] = $delivery['whatsapp_url'] ?? ($payload['whatsapp_url'] ?? null);
        $log->payload = $payload;

        if (($delivery['delivered'] ?? false) === true) {
            $this->notificationService->markSent($log, [
                'delivery_mode' => $delivery['mode'] ?? 'business_api',
                'external_id' => $delivery['external_id'] ?? null,
            ]);

            return [
                'notification_id' => $log->id,
                'status' => 'sent',
                'delivery_mode' => $delivery['mode'] ?? 'business_api',
                'external_id' => $delivery['external_id'] ?? null,
            ];
        }

        if (($delivery['mode'] ?? '') === 'manual_link') {
            $this->notificationService->markSent($log, [
                'delivery_mode' => 'manual_link',
                'note' => 'Queued for manual WhatsApp follow-up via wa.me link.',
            ]);

            return [
                'notification_id' => $log->id,
                'status' => 'sent',
                'delivery_mode' => 'manual_link',
                'whatsapp_url' => $delivery['whatsapp_url'] ?? null,
            ];
        }

        $this->notificationService->markFailed($log, (string) ($delivery['error'] ?? 'WhatsApp delivery failed.'));

        return [
            'notification_id' => $log->id,
            'status' => 'failed',
            'delivery_mode' => $delivery['mode'] ?? 'api_error',
            'error' => $delivery['error'] ?? 'WhatsApp delivery failed.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(int $tenantId): array
    {
        $base = DonationNotificationLog::forTenant($tenantId)->where('channel', 'whatsapp');

        return [
            'queued' => (clone $base)->where('status', 'queued')->count(),
            'sent' => (clone $base)->where('status', 'sent')->count(),
            'failed' => (clone $base)->where('status', 'failed')->count(),
            'business_api_enabled' => $this->businessApiService->isEnabled(),
            'recent' => (clone $base)->orderByDesc('created_at')->limit(5)->get([
                'id',
                'notification_type',
                'recipient',
                'status',
                'payload',
                'sent_at',
                'created_at',
            ]),
        ];
    }
}
