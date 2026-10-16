<?php

namespace Modules\ApplicationAccess\Support;

use Illuminate\Support\Facades\Cache;

final class ApplicationAccessViewThrottle
{
    public function shouldRecord(string $sessionId, string $normalizedRoute): bool
    {
        $seconds = (int) config('applicationaccess.view_throttle_seconds', 60);
        $cacheKey = 'application_access:view:'.$sessionId.':'.sha1($normalizedRoute);

        if (Cache::has($cacheKey)) {
            return false;
        }

        Cache::put($cacheKey, true, $seconds);

        return true;
    }
}
