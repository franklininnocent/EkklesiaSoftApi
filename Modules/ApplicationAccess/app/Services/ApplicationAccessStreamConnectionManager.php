<?php

namespace Modules\ApplicationAccess\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\ApplicationAccess\Exceptions\ApplicationAccessStreamCapacityExceededException;

class ApplicationAccessStreamConnectionManager
{
    private const GLOBAL_KEY = 'application_access:sse:connections';

    public function acquire(int $userId): string
    {
        $connectionId = (string) Str::uuid();
        $ttl = $this->ttlSeconds();
        $maxConnections = $this->maxConnections();

        $connections = $this->connections();
        if (count($connections) >= $maxConnections) {
            throw new ApplicationAccessStreamCapacityExceededException;
        }

        $userKey = $this->userKey($userId);
        $previous = Cache::get($userKey);
        if (is_string($previous) && $previous !== '') {
            Cache::put($this->replacedKey($previous), true, $ttl);
        }

        $connections[$connectionId] = [
            'user_id' => $userId,
            'started_at' => now()->toIso8601String(),
        ];

        Cache::put(self::GLOBAL_KEY, $connections, $ttl);
        Cache::put($userKey, $connectionId, $ttl);

        return $connectionId;
    }

    public function release(string $connectionId, int $userId): void
    {
        $connections = $this->connections();
        unset($connections[$connectionId]);
        Cache::put(self::GLOBAL_KEY, $connections, $this->ttlSeconds());

        $userKey = $this->userKey($userId);
        if (Cache::get($userKey) === $connectionId) {
            Cache::forget($userKey);
        }

        Cache::forget($this->replacedKey($connectionId));
    }

    public function isReplaced(string $connectionId, int $userId): bool
    {
        if (Cache::get($this->replacedKey($connectionId)) === true) {
            return true;
        }

        return Cache::get($this->userKey($userId)) !== $connectionId;
    }

    public function hasCapacity(): bool
    {
        return count($this->connections()) < $this->maxConnections();
    }

    /**
     * @return array<string, array{user_id: int, started_at: string}>
     */
    public function connections(): array
    {
        $connections = Cache::get(self::GLOBAL_KEY, []);

        return is_array($connections) ? $connections : [];
    }

    private function userKey(int $userId): string
    {
        return 'application_access:sse:user:'.$userId;
    }

    private function replacedKey(string $connectionId): string
    {
        return 'application_access:sse:replaced:'.$connectionId;
    }

    private function ttlSeconds(): int
    {
        return max(30, (int) config('applicationaccess.sse.ttl_seconds', 120));
    }

    private function maxConnections(): int
    {
        return max(1, (int) config('applicationaccess.sse.max_connections', 10));
    }
}
