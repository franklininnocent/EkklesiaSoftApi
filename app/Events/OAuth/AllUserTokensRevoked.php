<?php

namespace App\Events\OAuth;

class AllUserTokensRevoked
{
    public function __construct(
        public readonly int $userId,
    ) {}
}
