<?php

namespace App\Events\OAuth;

class AccessTokenRotated
{
    public function __construct(
        public readonly int $userId,
        public readonly string $previousAccessTokenId,
    ) {}
}
