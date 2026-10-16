<?php

namespace Modules\Donations\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppBusinessApiService
{
    public function isEnabled(): bool
    {
        if (!(bool) config('whatsapp_business.enabled', false)) {
            return false;
        }

        return filled(config('whatsapp_business.phone_number_id'))
            && filled(config('whatsapp_business.access_token'));
    }

    /**
     * @return array<string, mixed>
     */
    public function sendTextMessage(string $phone, string $message): array
    {
        if (!$this->isEnabled()) {
            return [
                'delivered' => false,
                'mode' => 'manual_link',
                'whatsapp_url' => $this->buildWhatsAppUrl($phone, $message),
                'message' => 'WhatsApp Business API is not configured. Use the manual link.',
            ];
        }

        $version = (string) config('whatsapp_business.api_version', 'v21.0');
        $phoneNumberId = (string) config('whatsapp_business.phone_number_id');
        $url = sprintf('https://graph.facebook.com/%s/%s/messages', $version, $phoneNumberId);

        try {
            $response = Http::timeout((int) config('whatsapp_business.timeout', 15))
                ->withToken((string) config('whatsapp_business.access_token'))
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'to' => $phone,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $message,
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('WhatsApp Business API delivery failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return [
                    'delivered' => false,
                    'mode' => 'api_error',
                    'error' => $response->json('error.message') ?? 'WhatsApp API request failed.',
                    'whatsapp_url' => $this->buildWhatsAppUrl($phone, $message),
                ];
            }

            return [
                'delivered' => true,
                'mode' => 'business_api',
                'external_id' => $response->json('messages.0.id'),
                'whatsapp_url' => $this->buildWhatsAppUrl($phone, $message),
            ];
        } catch (\Throwable $exception) {
            Log::error('WhatsApp Business API exception', ['message' => $exception->getMessage()]);

            return [
                'delivered' => false,
                'mode' => 'api_exception',
                'error' => $exception->getMessage(),
                'whatsapp_url' => $this->buildWhatsAppUrl($phone, $message),
            ];
        }
    }

    private function buildWhatsAppUrl(string $phone, string $message): string
    {
        return 'https://wa.me/' . ltrim($phone, '+') . '?text=' . rawurlencode($message);
    }
}
