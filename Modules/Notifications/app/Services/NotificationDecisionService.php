<?php

namespace Modules\Notifications\Services;

use Modules\Notifications\Models\NotificationDefinition;
use Modules\Notifications\Support\NotificationIntent;
use Modules\Notifications\Support\NotificationSuppression;

class NotificationDecisionService
{
    public function shouldPublish(NotificationDefinition $definition, NotificationIntent $intent): bool
    {
        if (! $definition->active) {
            return false;
        }

        if (NotificationSuppression::isQuiet() && ! $definition->mandatory && $definition->priority === 'low') {
            return false;
        }

        if (! $this->passesStormThrottle($intent)) {
            return $definition->mandatory;
        }

        return true;
    }

    private function passesStormThrottle(NotificationIntent $intent): bool
    {
        if (! config('notifications.storm.enabled', true)) {
            return true;
        }

        if ($intent->tenantId === null) {
            return true;
        }

        $max = (int) config('notifications.storm.max_per_tenant_per_minute', 60);
        $key = sprintf('notif:storm:%d:%s', $intent->tenantId, now()->format('YmdHi'));

        try {
            $count = (int) cache()->increment($key);
            if ($count === 1) {
                cache()->put($key, 1, now()->addMinutes(2));
            }

            return $count <= $max;
        } catch (\Throwable) {
            return true;
        }
    }
}
