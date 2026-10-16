<?php

namespace Modules\ApplicationAccess\Services;

use Illuminate\Http\Request;
use Modules\ApplicationAccess\Support\ApplicationAccessStreamEmitter;
use Modules\Authentication\Models\User;

class ApplicationAccessStreamService
{
    public function __construct(
        private readonly ApplicationAccessStreamConnectionManager $connections,
        private readonly ApplicationAccessStreamAuthorizer $authorizer,
        private readonly ApplicationAccessStreamEventPublisher $publisher,
        private readonly ApplicationAccessStreamEmitter $emitter,
    ) {}

    public function run(Request $request, User $user, ?int $maxIterations = null): void
    {
        $ttl = max(1, (int) config('applicationaccess.sse.ttl_seconds', 120));
        $heartbeatSeconds = max(1, (int) config('applicationaccess.sse.heartbeat_seconds', 20));
        $pollMicroseconds = max(0, (int) config('applicationaccess.sse.poll_interval_ms', 1000)) * 1000;

        set_time_limit($ttl + 5);
        $this->emitter->prepareStream();

        $connectionId = $this->connections->acquire((int) $user->id);
        $startedAt = time();
        $deadline = $startedAt + $ttl;
        $lastHeartbeat = $startedAt;

        $lastAccessId = null;
        $lastSecurityId = null;
        $since = now()->subSecond();

        try {
            foreach ($this->publisher->replayFromLastEventId($request->header('Last-Event-ID')) as $event) {
                $this->emitTelemetryEvent($event);
                [$lastAccessId, $lastSecurityId] = $this->trackCursor($event, $lastAccessId, $lastSecurityId);
            }

            $iterations = 0;
            while (time() < $deadline) {
                if (! $this->authorizer->canStream($request, $user)) {
                    break;
                }

                if ($this->connections->isReplaced($connectionId, (int) $user->id)) {
                    $this->emitter->commentEvent('replaced', ['reason' => 'replaced']);
                    break;
                }

                foreach ($this->publisher->fetchSince($since, $lastAccessId, $lastSecurityId) as $event) {
                    $this->emitTelemetryEvent($event);
                    [$lastAccessId, $lastSecurityId] = $this->trackCursor($event, $lastAccessId, $lastSecurityId);
                }

                if (time() - $lastHeartbeat >= $heartbeatSeconds) {
                    $this->emitter->ping();
                    $lastHeartbeat = time();
                }

                if ($maxIterations !== null && ++$iterations >= $maxIterations) {
                    break;
                }

                if ($pollMicroseconds > 0) {
                    usleep($pollMicroseconds);
                }
            }

            if (time() >= $deadline) {
                $this->emitter->commentEvent('reconnect', ['reason' => 'ttl']);
            }
        } finally {
            $this->connections->release($connectionId, (int) $user->id);
        }
    }

    /**
     * @param  array{stream_id: string, stream_type: string, occurred_at: string, payload: array<string, mixed>}  $event
     */
    private function emitTelemetryEvent(array $event): void
    {
        $this->emitter->emit('telemetry', $event['stream_id'], [
            'stream_type' => $event['stream_type'],
            'occurred_at' => $event['occurred_at'],
            'payload' => $event['payload'],
        ]);
    }

    /**
     * @param  array{stream_id: string, stream_type: string}  $event
     * @return array{0: ?string, 1: ?string}
     */
    private function trackCursor(array $event, ?string $lastAccessId, ?string $lastSecurityId): array
    {
        if ($event['stream_type'] === 'access') {
            $lastAccessId = (string) ($event['payload']['id'] ?? str_replace('access:', '', $event['stream_id']));
        }

        if ($event['stream_type'] === 'security') {
            $lastSecurityId = (string) ($event['payload']['id'] ?? str_replace('security:', '', $event['stream_id']));
        }

        return [$lastAccessId, $lastSecurityId];
    }
}
