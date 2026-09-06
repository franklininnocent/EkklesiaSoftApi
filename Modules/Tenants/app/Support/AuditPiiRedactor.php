<?php

namespace Modules\Tenants\Support;

class AuditPiiRedactor
{
    /**
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'remember_token',
        'token',
        'access_token',
        'refresh_token',
        'secret',
        'api_key',
        'authorization',
        'ssn',
        'social_security',
        'credit_card',
        'card_number',
        'cvv',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redact($value);

                continue;
            }

            if (is_string($value) && $this->isMaskableKey((string) $key)) {
                $redacted[$key] = $this->redactStringValue((string) $key, $value);

                continue;
            }

            if (is_string($value) && strlen($value) > 500) {
                $redacted[$key] = substr($value, 0, 500).'…[truncated]';

                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($normalized === $sensitive || str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private function isMaskableKey(string $key): bool
    {
        return in_array(strtolower($key), ['email', 'user_email', 'assigned_by_email', 'payer_email', 'phone', 'payer_phone', 'mobile'], true);
    }

    private function redactStringValue(string $key, string $value): string
    {
        $normalized = strtolower($key);

        if (in_array($normalized, ['email', 'user_email', 'assigned_by_email', 'payer_email'], true)) {
            return $this->maskEmail($value);
        }

        if (in_array($normalized, ['phone', 'payer_phone', 'mobile'], true)) {
            return $this->maskPhone($value);
        }

        if (strlen($value) > 500) {
            return substr($value, 0, 500).'…[truncated]';
        }

        return $value;
    }

    private function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '[redacted]';
        }

        [$local, $domain] = explode('@', $email, 2);

        return substr($local, 0, 1).'***@'.$domain;
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return '[redacted]';
        }

        return '***'.substr($digits, -4);
    }
}
