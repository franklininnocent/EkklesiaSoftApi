<?php

namespace App\Support\Email;

/**
 * Sanitize email subjects to prevent header injection (CR/LF).
 */
final class EmailSubject
{
    public static function sanitize(string $subject): string
    {
        $clean = preg_replace('/[\r\n]+/', ' ', $subject) ?? $subject;

        return trim($clean);
    }
}
