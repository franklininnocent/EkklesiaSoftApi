<?php

namespace Modules\Notifications\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Notifications\Models\UserNotification;

/** @mixin UserNotification */
class UserNotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $event = $this->event;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'action_status' => $this->action_status,
            'is_mention' => $this->is_mention,
            'read_at' => $this->read_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'title' => $event?->title,
            'body' => $event?->body,
            'category' => $event?->category,
            'module' => $event?->module,
            'priority' => $event?->priority,
            'event_type' => $event?->event_type,
            'definition_code' => $event?->definition?->code,
            'actor_display_name' => $event?->actor_display_name,
            'subject_type' => $event?->subject_type,
            'subject_id' => $event?->subject_id,
        ];
    }
}
