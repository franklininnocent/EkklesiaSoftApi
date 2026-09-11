<?php

namespace App\Events\Auth;

class LoginFailed
{
    public function __construct(
        public readonly ?string $emailAttempt,
        public readonly string $reasonCode,
    ) {}
}
