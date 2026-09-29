<?php

namespace Modules\Notifications\Contracts;

use Modules\Notifications\Models\NotificationEvent;
use Modules\Notifications\Support\NotificationIntent;

interface NotificationPublisherContract
{
    public function publish(NotificationIntent $intent): ?NotificationEvent;

    public function completeSubject(string $subjectType, string $subjectId): void;
}
