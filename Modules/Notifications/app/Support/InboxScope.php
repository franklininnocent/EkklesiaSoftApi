<?php

namespace Modules\Notifications\Support;

enum InboxScope: string
{
    case Platform = 'platform';
    case Tenant = 'tenant';
}
