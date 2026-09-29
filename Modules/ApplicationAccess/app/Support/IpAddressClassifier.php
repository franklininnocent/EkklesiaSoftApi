<?php

namespace Modules\ApplicationAccess\Support;

final class IpAddressClassifier
{
    public const CLASS_PUBLIC = 'PUBLIC';

    public const CLASS_PRIVATE = 'PRIVATE';

    public const CLASS_LOOPBACK = 'LOOPBACK';

    public const CLASS_INTERNAL = 'INTERNAL';

    public const CLASS_UNKNOWN = 'UNKNOWN';

    /**
     * @return array{ip_address: ?string, ip_version: ?int, ip_class: string}
     */
    public function classify(?string $ip): array
    {
        if ($ip === null || trim($ip) === '') {
            return [
                'ip_address' => null,
                'ip_version' => null,
                'ip_class' => self::CLASS_UNKNOWN,
            ];
        }

        $normalized = $this->normalize($ip);

        if ($normalized === null) {
            return [
                'ip_address' => null,
                'ip_version' => null,
                'ip_class' => self::CLASS_UNKNOWN,
            ];
        }

        $version = filter_var($normalized, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 4 : 6;

        if ($this->isLoopback($normalized, $version)) {
            return [
                'ip_address' => $normalized,
                'ip_version' => $version,
                'ip_class' => self::CLASS_LOOPBACK,
            ];
        }

        if ($this->isPrivate($normalized, $version)) {
            return [
                'ip_address' => $normalized,
                'ip_version' => $version,
                'ip_class' => self::CLASS_PRIVATE,
            ];
        }

        if ($this->isLinkLocalOrUniqueLocal($normalized, $version)) {
            return [
                'ip_address' => $normalized,
                'ip_version' => $version,
                'ip_class' => self::CLASS_INTERNAL,
            ];
        }

        return [
            'ip_address' => $normalized,
            'ip_version' => $version,
            'ip_class' => self::CLASS_PUBLIC,
        ];
    }

    public function normalize(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        $ip = trim($ip);

        if (str_starts_with($ip, '::ffff:')) {
            $mapped = substr($ip, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $mapped;
            }
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        return $ip;
    }

    private function isLoopback(string $ip, int $version): bool
    {
        if ($version === 4) {
            return $ip === '127.0.0.1' || str_starts_with($ip, '127.');
        }

        return $ip === '::1' || str_starts_with($ip, '0:0:0:0:0:0:0:1');
    }

    private function isPrivate(string $ip, int $version): bool
    {
        if ($version === 4) {
            if (str_starts_with($ip, '169.254.')) {
                return false;
            }

            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE) === false
                && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }

        return $this->isIpv6UniqueLocal($ip);
    }

    private function isLinkLocalOrUniqueLocal(string $ip, int $version): bool
    {
        if ($version === 4) {
            return str_starts_with($ip, '169.254.');
        }

        $lower = strtolower($ip);

        return str_starts_with($lower, 'fe80:') || str_starts_with($lower, 'fec0:');
    }

    private function isIpv6UniqueLocal(string $ip): bool
    {
        $lower = strtolower($ip);

        return str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd');
    }
}
