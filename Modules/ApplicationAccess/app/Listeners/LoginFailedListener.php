<?php

namespace Modules\ApplicationAccess\Listeners;

use App\Events\Auth\LoginFailed;
use Modules\ApplicationAccess\Services\ApplicationAccessSessionLifecycleService;

class LoginFailedListener
{
    public function __construct(
        private readonly ApplicationAccessSessionLifecycleService $lifecycle,
    ) {}

    public function handle(LoginFailed $event): void
    {
        $this->lifecycle->handleLoginFailed($event);
    }
}
