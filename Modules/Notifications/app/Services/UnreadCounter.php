<?php

namespace Modules\Notifications\Services;

use Modules\Notifications\Models\UserNotification;
use Modules\Notifications\Support\InboxContext;
use Modules\Notifications\Support\InboxScope;

class UnreadCounter
{
    public function count(InboxContext $context): int
    {
        $cacheKey = $this->cacheKey($context);

        try {
            $cached = cache()->get($cacheKey);
            if ($cached !== null) {
                return (int) $cached;
            }
        } catch (\Throwable) {
            // fail open to DB
        }

        $count = $this->countFromDatabase($context);

        try {
            cache()->put($cacheKey, $count, now()->addMinutes(5));
        } catch (\Throwable) {
            // ignore
        }

        return $count;
    }

    public function invalidate(InboxContext $context): void
    {
        try {
            cache()->forget($this->cacheKey($context));
        } catch (\Throwable) {
            // ignore
        }
    }

    private function countFromDatabase(InboxContext $context): int
    {
        $query = UserNotification::query()
            ->where('user_id', $context->userId)
            ->where('inbox_scope', $context->scope->value)
            ->where('status', 'unread')
            ->whereNull('archived_at');

        if ($context->scope === InboxScope::Tenant) {
            $query->where('tenant_id', $context->tenantId);
        } else {
            $query->whereNull('tenant_id');
        }

        return $query->count();
    }

    private function cacheKey(InboxContext $context): string
    {
        return 'inbox:unread:'.$context->userId.':'.$context->cacheKeySuffix();
    }
}
