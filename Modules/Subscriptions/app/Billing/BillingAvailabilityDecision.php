<?php

namespace Modules\Subscriptions\Billing;

final class BillingAvailabilityDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reasonCode,
        public readonly string $message,
    ) {}

    public static function allow(): self
    {
        return new self(true, 'allowed', 'Allowed.');
    }

    public static function deny(string $reasonCode, string $message): self
    {
        return new self(false, $reasonCode, $message);
    }
}
