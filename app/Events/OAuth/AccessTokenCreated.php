<?php

namespace App\Events\OAuth;

class AccessTokenCreated
{
    public function __construct(
        public readonly int $userId,
        public readonly string $accessTokenId,
        public readonly ?string $previousAccessTokenId = null,
        public readonly ?string $authContext = null,
    ) {}
}
