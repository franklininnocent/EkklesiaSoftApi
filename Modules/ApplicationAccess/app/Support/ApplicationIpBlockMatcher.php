<?php

namespace Modules\ApplicationAccess\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\ApplicationAccess\Models\ApplicationIpBlockRule;
use Symfony\Component\HttpFoundation\IpUtils;

final class ApplicationIpBlockMatcher
{
    public function __construct(
        private readonly IpAddressClassifier $ipClassifier,
    ) {}

    public function isBlocked(?string $ip): bool
    {
        $normalized = $this->ipClassifier->normalize($ip);
        if ($normalized === null) {
            return false;
        }

        $meta = $this->ipClassifier->classify($normalized);
        if (in_array($meta['ip_class'], [
            IpAddressClassifier::CLASS_PRIVATE,
            IpAddressClassifier::CLASS_LOOPBACK,
            IpAddressClassifier::CLASS_INTERNAL,
        ], true)) {
            return false;
        }

        foreach ($this->activeRules() as $rule) {
            if ($this->matchesRule($normalized, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<ApplicationIpBlockRule>
     */
    private function activeRules(): array
    {
        try {
            return Cache::remember('application_access:active_ip_blocks', 30, function () {
                return ApplicationIpBlockRule::query()
                    ->whereNull('revoked_at')
                    ->where(function ($query): void {
                        $query->whereNull('expires_at')
                            ->orWhere('expires_at', '>', now());
                    })
                    ->get()
                    ->all();
            });
        } catch (\Throwable $exception) {
            // #region agent log
            try {
                @file_put_contents(dirname(__DIR__, 5).'/.cursor/debug-108c6b.log', json_encode([
                    'sessionId' => '108c6b',
                    'hypothesisId' => 'H2',
                    'location' => 'ApplicationIpBlockMatcher.php:activeRules',
                    'message' => 'ip block list unavailable',
                    'data' => ['type' => $exception::class],
                    'timestamp' => (int) round(microtime(true) * 1000),
                ])."\n", FILE_APPEND);
            } catch (\Throwable) {
            }
            // #endregion
            Log::warning('Application IP block list unavailable', [
                'exception' => $exception::class,
            ]);

            return [];
        }
    }

    private function matchesRule(string $ip, ApplicationIpBlockRule $rule): bool
    {
        if ($rule->cidr) {
            return IpUtils::checkIp($ip, $rule->cidr);
        }

        return $ip === $this->ipClassifier->normalize($rule->ip_address);
    }

    public static function flushCache(): void
    {
        Cache::forget('application_access:active_ip_blocks');
    }
}
