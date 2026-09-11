<?php

namespace App\Events\OAuth;

class AccessTokenRevoked
{
    public function __construct(
        public readonly string $accessTokenId,
        public readonly ?int $userId = null,
    ) {}
}
