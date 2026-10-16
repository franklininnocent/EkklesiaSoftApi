<?php

namespace Modules\Notifications\Services;

use Illuminate\Support\Carbon;
use Modules\Notifications\Models\UserNotification;
use Modules\Notifications\Support\InboxContext;
use Modules\Notifications\Support\InboxScope;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InboxStateService
{
    public function __construct(
        private readonly InboxQueryService $query,
        private readonly UnreadCounter $unreadCounter,
    ) {
    }

    public function markRead(InboxContext $context, string $id): UserNotification
    {
        $row = $this->findOwned($context, $id);
        $row->update(['status' => 'read', 'read_at' => now()]);
        $this->unreadCounter->invalidate($context);

        return $row->fresh(['event.definition']);
    }

    public function markUnread(InboxContext $context, string $id): UserNotification
    {
        $row = $this->findOwned($context, $id);
        $row->update(['status' => 'unread', 'read_at' => null]);
        $this->unreadCounter->invalidate($context);

        return $row->fresh(['event.definition']);
    }

    public function archive(InboxContext $context, string $id): UserNotification
    {
        $row = $this->findOwned($context, $id);
        $row->update(['archived_at' => now()]);
        $this->unreadCounter->invalidate($context);

        return $row->fresh(['event.definition']);
    }

    public function restore(InboxContext $context, string $id): UserNotification
    {
        $row = $this->findOwned($context, $id);
        $row->update(['archived_at' => null]);
        $this->unreadCounter->invalidate($context);

        return $row->fresh(['event.definition']);
    }

    /**
     * @return array{watermark: string, affected: int}
     */
    public function markAllRead(InboxContext $context, ?Carbon $watermark = null): array
    {
        $watermark = $watermark ?? now();

        $query = UserNotification::query()
            ->where('user_id', $context->userId)
            ->where('inbox_scope', $context->scope->value)
            ->where('status', 'unread')
            ->whereNull('archived_at')
            ->where('created_at', '<=', $watermark);

        if ($context->scope === InboxScope::Tenant) {
            $query->where('tenant_id', $context->tenantId);
        } else {
            $query->whereNull('tenant_id');
        }

        $affected = $query->update([
            'status' => 'read',
            'read_at' => now(),
            'updated_at' => now(),
        ]);

        $this->unreadCounter->invalidate($context);

        return [
            'watermark' => $watermark->toIso8601String(),
            'affected' => $affected,
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return int
     */
    public function bulkAction(InboxContext $context, string $action, array $ids): int
    {
        if (count($ids) > 100) {
            $ids = array_slice($ids, 0, 100);
        }

        $count = 0;
        foreach ($ids as $id) {
            match ($action) {
                'read' => $this->markRead($context, $id),
                'archive' => $this->archive($context, $id),
                'restore' => $this->restore($context, $id),
                default => throw new \InvalidArgumentException('Invalid bulk action'),
            };
            $count++;
        }

        return $count;
    }

    private function findOwned(InboxContext $context, string $id): UserNotification
    {
        $row = $this->query->findForUser($context, $id);
        if ($row === null) {
            throw new NotFoundHttpException('Notification not found.');
        }

        return $row;
    }
}
