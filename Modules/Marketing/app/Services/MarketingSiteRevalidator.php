<?php

namespace Modules\Marketing\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Optional on-demand Next.js cache refresh after marketing plan catalog publication.
 */
class MarketingSiteRevalidator
{
    /**
     * @return array{status: string, error: ?string}
     */
    public function revalidateMarketingSite(): array
    {
        $secret = (string) config('marketing.plan_catalog.revalidate_secret', '');
        $siteUrl = rtrim((string) config('marketing.plan_catalog.site_url', ''), '/');

        if ($secret === '' || $siteUrl === '') {
            return ['status' => 'skipped', 'error' => null];
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders(['X-Revalidate-Secret' => $secret])
                ->post("{$siteUrl}/api/revalidate-plans");

            if ($response->successful()) {
                return ['status' => 'succeeded', 'error' => null];
            }

            $message = 'Marketing site revalidate returned '.$response->status();

            return ['status' => 'failed', 'error' => $message];
        } catch (\Throwable $e) {
            Log::warning('marketing.plan_catalog.revalidate_failed', [
                'message' => $e->getMessage(),
            ]);

            return ['status' => 'failed', 'error' => 'Marketing site revalidate request failed.'];
        }
    }

    /**
     * @return array{status: string, error: ?string}
     */
    public function revalidatePlans(): array
    {
        return $this->revalidateMarketingSite();
    }
}
