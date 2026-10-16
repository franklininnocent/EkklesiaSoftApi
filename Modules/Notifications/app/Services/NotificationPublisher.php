<?php

namespace Modules\Notifications\Services;

use Illuminate\Support\Facades\DB;
use Modules\Notifications\Models\NotificationDefinition;
use Modules\Notifications\Models\NotificationEvent;
use Modules\Notifications\Models\UserNotification;
use Modules\Notifications\Support\NotificationIntent;

class NotificationPublisher implements \Modules\Notifications\Contracts\NotificationPublisherContract
{
    public function __construct(
        private readonly NotificationDecisionService $decision,
        private readonly RecipientResolver $recipientResolver,
        private readonly RecipientAuthorizer $recipientAuthorizer,
        private readonly NotificationTemplateRenderer $renderer,
        private readonly NotificationDataSanitizer $sanitizer,
        private readonly ChannelDispatcher $channelDispatcher,
        private readonly UnreadCounter $unreadCounter,
    ) {
    }

    public function publish(NotificationIntent $intent): ?NotificationEvent
    {
        $definition = NotificationDefinition::query()
            ->where('code', $intent->definitionCode)
            ->where('active', true)
            ->first();

        if ($definition === null) {
            return null;
        }

        if (! $this->decision->shouldPublish($definition, $intent)) {
            return null;
        }

        $data = $this->sanitizer->sanitize($intent->data);
        $title = $this->renderer->render($definition->title_template, $data);
        $body = $this->renderer->render($definition->body_template, $data);

        $idempotencyKey = sprintf(
            '%s:%s:%s:%s:%s',
            $intent->definitionCode,
            $intent->scope->value,
            $intent->tenantId ?? 'platform',
            $intent->subjectType ?? 'none',
            $intent->occurrenceId,
        );

        $existing = NotificationEvent::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $candidates = $this->recipientResolver->resolve($definition, $intent);
        $recipientIds = $this->recipientAuthorizer->authorize($candidates, $definition, $intent);

        if ($recipientIds === []) {
            return null;
        }

        return DB::transaction(function () use (
            $definition, $intent, $data, $title, $body, $idempotencyKey, $recipientIds
        ) {
            $collapseKey = $intent->collapseKey;

            if ($collapseKey !== null && $definition->collapse_mode === 'replace_unread') {
                UserNotification::query()
                    ->where('collapse_key', $collapseKey)
                    ->where('status', 'unread')
                    ->whereNull('archived_at')
                    ->update(['status' => 'read', 'read_at' => now()]);
            }

            $event = NotificationEvent::query()->create([
                'definition_id' => $definition->id,
                'event_type' => $definition->event_type,
                'category' => $definition->category,
                'module' => $definition->module,
                'priority' => $definition->priority,
                'inbox_scope' => $intent->scope->value,
                'tenant_id' => $intent->tenantId,
                'actor_user_id' => $intent->actor?->id,
                'actor_display_name' => $intent->actor?->name,
                'subject_type' => $intent->subjectType,
                'subject_id' => $intent->subjectId,
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'idempotency_key' => $idempotencyKey,
                'collapse_key' => $collapseKey,
            ]);

            foreach ($recipientIds as $userId) {
                $userNotification = UserNotification::query()->create([
                    'notification_event_id' => $event->id,
                    'user_id' => $userId,
                    'inbox_scope' => $intent->scope->value,
                    'tenant_id' => $intent->tenantId,
                    'status' => 'unread',
                    'action_status' => $intent->actionStatus,
                    'is_mention' => $intent->isMention,
                    'collapse_key' => $collapseKey,
                ]);

                $this->channelDispatcher->dispatch($userNotification, $definition);

                $context = new \Modules\Notifications\Support\InboxContext(
                    scope: $intent->scope,
                    tenantId: $intent->tenantId,
                    userId: $userId,
                );
                $this->unreadCounter->invalidate($context);
            }

            return $event;
        });
    }

    public function completeSubject(string $subjectType, string $subjectId): void
    {
        UserNotification::query()
            ->whereHas('event', function ($q) use ($subjectType, $subjectId) {
                $q->where('subject_type', $subjectType)->where('subject_id', $subjectId);
            })
            ->where('action_status', 'required')
            ->update(['action_status' => 'completed']);
    }
}
