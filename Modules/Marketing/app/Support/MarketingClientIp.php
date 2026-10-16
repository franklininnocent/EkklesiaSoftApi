<?php

namespace Modules\Marketing\Support;

use Illuminate\Http\Request;

final class MarketingClientIp
{
    public static function from(Request $request): ?string
    {
        $secret = (string) config('marketing.bff.shared_secret', '');
        $provided = (string) $request->header('X-Marketing-Proxy-Secret', '');
        if ($secret !== '' && $provided !== '' && hash_equals($secret, $provided)) {
            $forwarded = trim((string) $request->header('X-Marketing-Client-Ip', ''));
            if (filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
                return $forwarded;
            }
        }

        $ip = $request->ip();

        return is_string($ip) && $ip !== '' ? $ip : null;
    }
}
