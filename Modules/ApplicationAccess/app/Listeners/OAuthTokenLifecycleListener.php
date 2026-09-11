<?php

namespace Modules\ApplicationAccess\Listeners;

use App\Events\OAuth\AccessTokenCreated;
use App\Events\OAuth\AccessTokenRevoked;
use App\Events\OAuth\AccessTokenRotated;
use App\Events\OAuth\AllUserTokensRevoked;
use Modules\ApplicationAccess\Services\ApplicationAccessSessionLifecycleService;

class OAuthTokenLifecycleListener
{
    public function __construct(
        private readonly ApplicationAccessSessionLifecycleService $lifecycle,
    ) {}

    public function handleAccessTokenCreated(AccessTokenCreated $event): void
    {
        $this->lifecycle->handleAccessTokenCreated($event);
    }

    public function handleAccessTokenRotated(AccessTokenRotated $event): void
    {
        $this->lifecycle->handleAccessTokenRotated($event);
    }

    public function handleAccessTokenRevoked(AccessTokenRevoked $event): void
    {
        $this->lifecycle->handleAccessTokenRevoked($event);
    }

    public function handleAllUserTokensRevoked(AllUserTokensRevoked $event): void
    {
        $this->lifecycle->handleAllUserTokensRevoked($event);
    }
}
