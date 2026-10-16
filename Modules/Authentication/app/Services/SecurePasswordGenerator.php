<?php

namespace Modules\Authentication\Services;

use Modules\Authentication\Models\PasswordHistory;
use Modules\Authentication\Models\User;
use Modules\Authentication\Support\PasswordPolicy;

class SecurePasswordGenerator
{
    private const LENGTH = 16;

    /** @var string Excludes ambiguous glyphs O/0/I/l/1 while satisfying PasswordPolicy regex. */
    private const CHARSET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@$!%*?&';

    public function generate(): string
    {
        return $this->generateCompliant();
    }

    public function generateForUser(User $user): string
    {
        $maxAttempts = 50;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $password = $this->generateCompliant();

            if ($this->passesHistoryCheck($user, $password)) {
                return $password;
            }
        }

        return $this->generateCompliant();
    }

    private function generateCompliant(): string
    {
        $maxAttempts = 50;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $password = $this->buildRandom(self::LENGTH);

            if (preg_match(PasswordPolicy::COMPLEXITY_REGEX, $password) === 1) {
                return $password;
            }
        }

        return $this->buildGuaranteedCompliant();
    }

    private function buildRandom(int $length): string
    {
        $charset = self::CHARSET;
        $maxIndex = strlen($charset) - 1;
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $charset[random_int(0, $maxIndex)];
        }

        return $result;
    }

    private function buildGuaranteedCompliant(): string
    {
        $lower = 'abcdefghjkmnpqrstuvwxyz';
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $special = '@$!%*?&';

        $parts = [
            $lower[random_int(0, strlen($lower) - 1)],
            $upper[random_int(0, strlen($upper) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $special[random_int(0, strlen($special) - 1)],
        ];

        $remaining = self::LENGTH - count($parts);
        $charset = self::CHARSET;

        for ($i = 0; $i < $remaining; $i++) {
            $parts[] = $charset[random_int(0, strlen($charset) - 1)];
        }

        shuffle($parts);

        return implode('', $parts);
    }

    private function passesHistoryCheck(User $user, string $password): bool
    {
        $recentHashes = PasswordHistory::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(PasswordPolicy::HISTORY_LIMIT)
            ->pluck('password_hash');

        foreach ($recentHashes as $hash) {
            if (\Illuminate\Support\Facades\Hash::check($password, $hash)) {
                return false;
            }
        }

        return true;
    }
}
