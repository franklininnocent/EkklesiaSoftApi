<?php

namespace App\Support;

/**
 * Production CORS must not include loopback origins when credentials are enabled.
 */
final class CorsAllowedOrigins
{
    /**
     * Official public origins. Production must allow these even when
     * FRONTEND_URL / MARKETING_URL were omitted or baked empty into a config cache.
     *
     * @var list<string>
     */
    private const OFFICIAL_PRODUCTION_ORIGINS = [
        'https://app.ekklesianexus.com',
        'https://ekklesianexus.com',
    ];

    /**
     * @return list<string>
     */
    public static function resolve(?string $appEnv, ?string $frontendUrl, ?string $marketingUrl): array
    {
        $isLocal = in_array($appEnv, ['local', 'testing'], true);
        $origins = [];

        if ($isLocal) {
            $origins[] = 'http://localhost:4200';
            $origins[] = 'http://127.0.0.1:4200';
        } else {
            foreach (self::OFFICIAL_PRODUCTION_ORIGINS as $origin) {
                $origins[] = $origin;
            }
        }

        foreach ([$frontendUrl, $marketingUrl] as $url) {
            $origin = self::toOrigin($url);
            if ($origin === null) {
                continue;
            }

            $host = parse_url($origin, PHP_URL_HOST);
            if (! $isLocal && in_array($host, ['localhost', '127.0.0.1'], true)) {
                continue;
            }

            $origins[] = $origin;
        }

        return array_values(array_unique($origins));
    }

    /**
     * Browser Origin is scheme + host + port. A path or trailing slash must not be stored.
     */
    private static function toOrigin(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
