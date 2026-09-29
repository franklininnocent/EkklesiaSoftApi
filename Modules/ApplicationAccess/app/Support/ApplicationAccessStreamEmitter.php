<?php

namespace Modules\ApplicationAccess\Support;

final class ApplicationAccessStreamEmitter
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function emit(string $eventName, string $eventId, array $payload): void
    {
        echo 'id: '.$eventId."\n";
        echo 'event: '.$eventName."\n";
        echo 'data: '.json_encode($payload, JSON_THROW_ON_ERROR)."\n\n";
        $this->flush();
    }

    public function ping(): void
    {
        echo ": ping\n\n";
        $this->flush();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function commentEvent(string $eventName, array $payload = []): void
    {
        echo 'event: '.$eventName."\n";
        echo 'data: '.json_encode($payload, JSON_THROW_ON_ERROR)."\n\n";
        $this->flush();
    }

    public function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    public function prepareStream(): void
    {
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
    }
}
