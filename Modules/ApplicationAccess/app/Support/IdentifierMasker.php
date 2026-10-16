<?php

namespace Modules\ApplicationAccess\Support;

final class IdentifierMasker
{
    public static function maskEmail(?string $email): ?string
    {
        if ($email === null || $email === '' || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        return substr($local, 0, 1).'***@'.$domain;
    }
}
