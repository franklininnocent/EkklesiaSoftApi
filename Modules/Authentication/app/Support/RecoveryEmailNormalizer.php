<?php

namespace Modules\Authentication\Support;

final class RecoveryEmailNormalizer
{
    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }
}
