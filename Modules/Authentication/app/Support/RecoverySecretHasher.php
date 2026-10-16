<?php

namespace Modules\Authentication\Support;

final class RecoverySecretHasher
{
    public static function pepper(): string
    {
        $configured = config('authentication.recovery.pepper');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return hash_hmac('sha256', (string) config('app.key'), 'ekklesia-recovery-pepper');
    }

    public static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, self::pepper());
    }

    public static function verify(string $value, string $expectedHash): bool
    {
        return hash_equals($expectedHash, self::hash($value));
    }

    public static function hashEmail(string $normalizedEmail): string
    {
        return self::hash('email:'.$normalizedEmail);
    }
}
