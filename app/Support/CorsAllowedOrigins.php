<?php

namespace App\Support;

/**
 * Production CORS must not include loopback origins when credentials are enabled.
 */
final class CorsAllowedOrigins
{
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
        }

        foreach ([$frontendUrl, $marketingUrl] as $url) {
            if (! is_string($url) || trim($url) === '') {
                continue;
            }

            $host = parse_url($url, PHP_URL_HOST);
            if (! $isLocal && in_array($host, ['localhost', '127.0.0.1'], true)) {
                continue;
            }

            $origins[] = rtrim($url, '/');
        }

        return array_values(array_unique($origins));
    }
}
