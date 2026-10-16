<?php

namespace Modules\Authentication\Services;

class OtpGenerator
{
    private const CHARSET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    public function generate(int $length = 6): string
    {
        $max = strlen(self::CHARSET) - 1;
        $otp = '';

        for ($i = 0; $i < $length; $i++) {
            $otp .= self::CHARSET[random_int(0, $max)];
        }

        return $otp;
    }
}
